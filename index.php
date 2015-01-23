<?php
require_once("config.php");
require_once("code/StatTracker.class.php");
require_once("code/Agent.class.php");
require_once("code/Authentication.class.php");
require_once("vendor/autoload.php");

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$db = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8", DB_HOST, DB_NAME), DB_USER, DB_PASS, array(
	PDO::ATTR_EMULATE_PREPARES   => false,
	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
));

$app = new Silex\Application();
$app['debug'] = true;
$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
	'twig.path' => __DIR__ . "/views",
));

$agent = new Agent();
if ($app['session']->get("agent") !== null) {
	$agent = $app['session']->get("agent");
}

// Default handler. Will match any alphnumeric string. If the page doesn't exist,
// 404
$app->get('/{page}', function ($page) use ($app) {
	if ($page == "dashboard" ||
	    $page == "submit-stats" ||
	    $page == "leaderboards") {
	
		return $app['twig']->render("index.twig", array(
			"constants" => array("ga_id" => GOOGLE_ANALYTICS_ID),
			"version_string" => empty(TAG_NAME) ? "version " . substr(COMMIT_HASH, 0, 7) : TAG_NAME,
			"page" => $page
		));
	}
	else if ($page == "terms-of-use") {
		return $app['twig']->render("terms.html");
	}
	else if ($page == "authenticate") {
		switch ($_REQUEST['action']) {
			case "login":
				return $app->json(Authentication::getInstance()->login());
				break;
			case "callback":
				if (Authentication::getInstance()->callback()) {
					return $app->redirect("./dashboard");
				}
				else {
					$app->abort(500, "An error occured during authentication");
				}
				break;
			case "logout":
				return $app->json(Authentication::getInstance()->logout());
				break;
			default:
				$app->abort(405, "Invalid Authentication action");
		}
	}
	else {
		$app->abort(404);
	}
})->assert('page', '[a-z-]+')
  ->value('page', 'dashboard');

$app->get('/page/{page}', function($page) use ($app, $agent) {
	if ($page == "submit-stats") {
		$agent->getLatestStats(true);
	}

	return $app['twig']->render($page.".twig", array(
		"agent" => $agent,
		"constants" => array("email_submission" => EMAIL_SUBMISSION),
		"stats" => StatTracker::getStats(),
		"faction_class" => $agent->faction == "R" ? "resistance-agent" : "enlightened-agent",
		"faction_color" => $agent->faction == "R" ? RES_BLUE : ENL_GREEN,
	));
});

$app->get("/resources/{resource}", function(Request $request, $resource) use ($app) {
	switch ($resource) {
		case "style.css":
			$file = "./resources/style.less";
			$lastModified = filemtime($file);
			$css = new Symfony\Component\HttpFoundation\Response("", 200, array("Content-Type" => "text/css"));
			$css->setLastModified(new \DateTime("@".filemtime($file)));

			if ($css->isNotModified($request)) {
				$css->setNotModified();
			}
			else {
				$parser = new Less_Parser(array("compress" => true));
				$parser->parseFile($file, $request->getBaseUrl());
				$css->setLastModified(new \DateTime("@".filemtime($file)));
				$css->setContent($parser->getCss());
			}

			return $css;
			break;
	}
});

$app->run();
?>
