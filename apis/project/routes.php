<?php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use \BreatheCode\BCWrapper;
use \JsonPDO\JsonPDO;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

BCWrapper::init(BREATHECODE_CLIENT_ID, BREATHECODE_CLIENT_SECRET, BREATHECODE_HOST, API_DEBUG);
BCWrapper::setToken(BREATHECODE_TOKEN);

return function($api){

    $fetchProject = function($p){
        $_options = ["bc.json", "learn.json"];
        $client = new Client();
        foreach($_options as $opt){
            $url = str_replace("https://github.com/", 'https://raw.githubusercontent.com/', $p->repository)."/master/$opt";
            try {
                $resp = $client->request('GET',$url);
            } catch (ClientException $e) {
                $resp = $e->getResponse();
                if($resp->getStatusCode() == 404) continue;
                else throw $e;
            }
            $body = $resp->getBody()->getContents();
            $json = json_decode($body);
            $newProject = array_merge((array) $json, (array) $p);
            $newProject["updated_at"] = strtotime("now");
            $newProject["readme"] = str_replace("https://github.com/", 'https://raw.githubusercontent.com/', $p->repository).'/master/README.md';
            
            if(!isset($newProject["translations"])) $newProject["translations"] = ["us"];
            else{
                foreach($newProject["translations"] as $lang){
                    if($lang != "us" && $lang != "en") $newProject["readme-".$lang] = str_replace("https://github.com/", 'https://raw.githubusercontent.com/', $p->repository).'/master/README.'.$lang.'.md';
                }
            }
        
            if(!isset($newProject["likes"])) $newProject["likes"] = 0;
            if(isset($newProject["difficulty"])){
                if($newProject["difficulty"] == "junior") $newProject["difficulty"] = "easy";
                else if($newProject["difficulty"] == "semi-senior") $newProject["difficulty"] = "intermediate";
                else if($newProject["difficulty"] == "senior") $newProject["difficulty"] = "hard";
            }
            
            if(!isset($newProject["downloads"])) $newProject["downloads"] = 0;
            return $newProject;
        }

        return null;
    };
    
    $api->get('/all', function (Request $request, Response $response, array $args) use ($api) {

        $projects = [];
        $slugs = [];
        $registry = $api->db['json']->getJsonByName('_registry');
        forEach($registry as $slug => $project){
            $projects[] = $project;
            $slugs[] = $slug;
        }
        
        $client = new Client();
        $resp = $client->request('GET','https://projects.breatheco.de/json/');
        if($resp->getStatusCode() != 200) throw new Exception('The project list was not found', 404);
        $oldProjectsBody = $resp->getBody()->getContents();
        $oldProjects = json_decode($oldProjectsBody);
        forEach($oldProjects as $old){
            if(!in_array($old->slug, $slugs)) $projects[] = $old;
        }
	    return $response->withJson($projects);
	});

	$api->get('/registry/all', function (Request $request, Response $response, array $args) use ($api) {
        
        $content = $api->db['json']->getJsonByName('_registry');
	    return $response->withJson($content);
    });

    $api->post('/registry/{project_slug}', function (Request $request, Response $response, array $args) use ($api, $fetchProject) {
        
        if(empty($args['project_slug'])) throw new Exception('Invalid param value project_slug');
		$data = (object) $request->getParsedBody();
        if(!$data) throw new Exception('The body must be an object');
		if(!isset($data->repository)) throw new Exception('Seed must contain a repository');
        
        // $data = $request->getParams();
        // if(!isset($data["bc_token"])) throw new Exception('Invalid breathecode token');
        // BCWrapper::setToken($data["bc_token"]);
        // $user = BCWrapper::getMe();
        
        $seed = $api->db['json']->getJsonByName('_seed');
        if(!is_array($seed)) throw new Exeption("Internal error: Invalid seed json", 500);
        $registry = $api->db['json']->getJsonByName('_registry');
        if(empty($registry)) throw new Exeption("Internal error: Invalid registry json", 500);
        
        $newProject = $fetchProject($data);
        if(empty($newProject)) throw new Exeption("Project learn.json or bc.json not found on github", 500);

        if($args['project_slug'] !== $newProject["slug"]) throw new Exception("The slug in the bc.json does not match the slug on the request URL", 400);
        
        if(!isset($registry[$args['project_slug']])){
            $new = [ 
                "slug" => $newProject["slug"], 
                "repository" => $newProject["repository"] 
            ];
            if(isset($user)) $new["user"] = $user->id;
            $seed[] = $new;
            $api->db['json']->toFile('_seed')->save($seed);
        }
        $registry[$newProject["slug"]] = $newProject;
        $api->db['json']->toFile('_registry')->save($registry);

        return $response->withJson($newProject);
        
    })->add($api->auth());

	$api->get('/registry/seed', function (Request $request, Response $response, array $args) use ($api) {
        
        $content = $api->db['json']->getJsonByName('_seed');
	    return $response->withJson($content);
    });

	$api->get('/registry/update', function (Request $request, Response $response, array $args) use ($api, $fetchProject) {

        if(!file_exists("./data/_registry.json")) file_put_contents("./data/_registry.json", "{}");

        $force = (empty($_GET['force'])) ? false : $_GET['force'] === "true";
        $seeds = $api->db['json']->getJsonByName('_seed');
        $registry = $api->db['json']->getJsonByName('_registry');
        forEach($seeds as $p){
            if(!empty($registry[$p->slug]) and !empty($registry[$p->slug]->updated_at)){
                $lastUpdate = $registry[$p->slug]->updated_at;
                $diff = (strtotime("now") - $lastUpdate);
                // more than one day
                if(!$force && $diff < 86400) continue; 
            }

            $newProject = $fetchProject($p);
            $registry[$newProject["slug"]] = $newProject;
        }
        $api->db['json']->toFile('_registry')->save($registry);

        //temporal hook to trigger buddy build when a new project is added
        $client = new Client();
        $resp = $client->request('GET',"https://app.buddy.works/breathecode/projects/pipelines/pipeline/239100/trigger-webhook?token=".BUDDY_TOKEN);

        return $response->withJson($registry);
    });

	$api->get('/{project_slug}', function (Request $request, Response $response, array $args) use ($api) {

        if(empty($args['project_slug'])) throw new Exception('Invalid param value project_slug');

        $registry = $api->db['json']->getJsonByName('_registry');
        if(!empty($registry[$args['project_slug']])) return $response->withJson($registry[$args['project_slug']]);

        $client = new Client();
        $resp = $client->request('GET','https://projects.breatheco.de/json/?slug='.$args['project_slug']);
        if($resp->getStatusCode() != 200) throw new Exception('The project was not found', 404);

        $body = $response->getBody();
		$body->write($resp->getBody()->getContents());
	    return $response->withHeader('Content-type', 'application/json');
    });

    return $api;
};
