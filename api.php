<?php
//$output_location = dirname(__FILE__) . '/output/'; // local dev
$output_location = dirname(dirname(__FILE__)) . '/';

/* SparkPost settings */
$sparkpost_api_key = '<SPARKPOST_API_KEY>'; # enter your Sparkpost API key
$sparkpost_template_id = '<TEMPLATE_ID>'; # enter the ID of the template you wish to use in Sparkpost

/* When a new article is published (or a draft created) it will send you an email notification */
$email_from_name = '<FROM_NAME>'; # enter the name the email appears to come from, e.g. Publish Notifications
$email_from_add = '<FROM_EMAIL_ADDRESS>'; # enter the email address the email appears to come from
$email_to_name = '<TO_NAME>'; # Enter the name of the person you're sending notifications to, e.g. Your Name
$email_to_add = '<FROM_EMAIL_ADDRESS>'; # Enter the email address you want to send notifications to

/* This needs to be supplied as part of the post call */
$auth_code = '<RANDOM_STRING>'; # create a random string to act as rudimentary security against unauthorised publishing

$data = json_decode(file_get_contents('php://input'), true);

if (@$data && @$data['auth_code'] == $auth_code) {
	if (@$data['contents_txt'] && strpos(@data['contents_txt'], 'http') == 0) {
		$file_contents = make_url_request($data['contents_txt']);
		require_once('inc/Parsedown.php');
		$Parsedown = new Parsedown();
		$html_content = $Parsedown->text($file_contents);
		if ($rendered_html = create_html_from_template('templates', 'article', array('title' => $data['title'], 'content' => $html_content))) {
			$slug = preg_replace('/^-+|-+$/', '', strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['title'])));
			$slug = ($data['status'] == 'draft') ? 'draft-' . $slug : $slug;
			if($article_location = create_article($slug, $rendered_html, $data['status'])) {
				if ($data['status'] == 'published') {
					if (!rebuild_index()) {
						return_result(array('error' => 'Error: Unable to build index'), 405);
					} else {
						remove_drafts();
					}
				}
				if (send_notification_email($data['status'], $data['title'], $article_location, $email_to_add, $email_to_name)) {
					return_result(array('success' => 'Article published'));
				} else {
					return_result(array('error' => 'Error: Sending notification email'), 405);
				}
			} else {
				return_result(array('error' => 'Error: There was an issue creating the file'), 405);
			}
		} else {
			return_result(array('error' => 'Error: Problem rendering HTML'), 405);
		}
	} else {
		return_result(array('error' => 'Error: Zapier hydrate URL not found'), 405);
	}
} else {
	return_result(array('error' => 'Error: no data supplied or auth_code did not match'), 405);
}

function remove_drafts() {
	global $output_location;
	if ($article_folders = scan_dir($output_location)) {
		foreach ($article_folders as $article) {
			if (substr($article, 0, 6) == 'draft-') {
				$draft_article_path = $output_location . $article;
				if (is_file($draft_article_path . '/index.html')) {
					unlink($draft_article_path . '/index.html');
				}
				if (is_dir($draft_article_path)) {
					rmdir($draft_article_path);
				}
			}
		}
	}
}

function rebuild_index() {
	global $output_location;
	if ($article_folders = scan_dir($output_location)) {
		$articles = array();
		foreach ($article_folders as $article) {
			if (substr($article, 0, 6) != 'draft-') {
				$html = file_get_contents($output_location . $article . '/index.html');
				if ($html) {
					$res = preg_match("/<title>(.*)<\/title>/siU", $html, $title_matches);
					if ($res) {
						$title = preg_replace('/\s+/', ' ', $title_matches[1]);
						$title = trim($title);
					} else {
						$title = '';
					}
				} else {
					$title = '';
				}
				$url = $article . '/index.html';
				array_push($articles, array('url' => $url, 'name' => $title));
			}
		}
		// render HTML
		if ($rendered_html = create_html_from_template('templates', 'list', array('articles' => $articles))) {
			// save to file
			if (file_put_contents($output_location . '/index.html', $rendered_html) !== FALSE) {
				return TRUE;
			} else {
				return FALSE;
			}
		} else {
			return FALSE;
		}
	} else {
		return FALSE;
	}
}

function scan_dir($dir) {
	// sort directory by date creaed
	// modified from https://stackoverflow.com/questions/11923235/scandir-to-sort-by-date-modified
    $ignored = array('.', '..', '.svn', '.htaccess', 'img', 'css', 'build');
    $files = array();    
    foreach (scandir($dir) as $file) {
        if (in_array($file, $ignored) || !is_dir($dir . '/' . $file)) continue;
        $files[$file] = filemtime($dir . '/' . $file);
    }

    arsort($files);
    $files = array_keys($files);

    return ($files) ? $files : FALSE;
}

function send_notification_email($type, $title = '', $url = '', $email_to_add, $email_to_name) {
	if ($msg_body = create_html_from_template('templates', 'notification-email', array('article_status' => $type, 'article_title' => $title, 'link_url' => $url))) {
		$substitution_data = array (
			'subj' => strtoupper($type) . ': ' . $title,
			'body_content' => $msg_body
		);
		return send_sparkpost_email($email_to_add,$email_to_name,$substitution_data);
	} else {
		return FALSE;
	}
}

function send_sparkpost_email($to_add,$to_name,$dynamic_variables) {
	require_once('inc/httpful.phar');
	global $sparkpost_api_key, $sparkpost_template_id, $email_from_add, $email_from_name;
	
	$url = 'https://api.sparkpost.com/api/v1/transmissions';
	$body = array(
		'content' => array(
			'template_id' => $sparkpost_template_id
		),
		'substitution_data' => $dynamic_variables,
		'return_path' => $email_from_add,
		'recipients' => array(
			array(
				'address' => array(
					'email' => $to_add,
					'name' => $to_name
				)
			)
		),
		'options' => array(
			'open_tracking' => false,
			'click_tracking' => false,
			'transactional' => true
		)
	);
	
	$result = \Httpful\Request::post($url)
		->sendsJson()
		->body($body)
		->addHeader('Authorization', $sparkpost_api_key)
		->send();
	
	if (@!$result->body->errors) {
		return TRUE;
	} else {
		return FALSE;
	}
}

function create_article($filename, $contents, $status) {
	global $output_location;
	$article_folder_path = $output_location . $filename;
	if (!is_dir($article_folder_path)) {
		mkdir($article_folder_path);
	} else {
		if ($status == 'published') {
			$version = 2;
			$versioned_folder_path = $article_folder_path . '-' . $version;
			while (is_dir($versioned_folder_path)) {
				$versioned_folder_path = $article_folder_path . '-' . $version;
				$version++;
			}
			mkdir($versioned_folder_path);
			$article_folder_path = $versioned_folder_path;
		}
	}
	if (file_put_contents($article_folder_path . '/index.html', $contents) !== FALSE) {
		$base_url = 'http://' . $_SERVER['SERVER_NAME'] . substr($_SERVER['SCRIPT_NAME'], 0, strrpos($_SERVER['SCRIPT_NAME'], '/' . basename(dirname(__FILE__)))) . '/';
		$article_url = str_replace($output_location, $base_url, $article_folder_path) . '/index.html';
		return $article_url;
	} else {
		return FALSE;
	}
}

function return_result($result, $http_code=200) {
	header("Access-Control-Allow-Origin: *");
	header("Content-Type: application/json; charset=utf-8");
	if ($http_code != 200) {
		http_response_code($http_code);
	} elseif (!$result OR @$result['error']) {
		http_response_code(400);
	}
	echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

function make_url_request($request_url, $request_body = '', $method = 'GET') {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $request_url);
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	if ($method == 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
			'Content-Type: application/json'
			)
		);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body); 
	}
	if (!$result = curl_exec($ch)) {
		return FALSE;
	}
	curl_close($ch);
	return $result;
}

function create_html_from_template($templates_folder, $template_file, $content) {
	require_once 'inc/Twig/lib/Twig/Autoloader.php';
    Twig_Autoloader::register(true);
	$template_file_path = dirname(__FILE__) . '/' . $templates_folder;
	$loader = new Twig_Loader_Filesystem($template_file_path);
	$twig = new Twig_Environment($loader, array('autoescape' => false));
	if ($rendered_content = $twig->render($template_file . '.twig.html', $content)) {
		return $rendered_content;
	} else {
		return FALSE;
	}
}

?>