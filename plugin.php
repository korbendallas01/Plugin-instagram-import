<?php

class KokenInstagram extends KokenPlugin {

	function __construct()
	{
		$this->database_fields = array(
			'content' => array(
				'koken_instagram' => array(
					'type' => 'TINYINT',
					'constraint' => 1,
					'default' => 0
				),
				'koken_instagram_captured' => array(
					'type' => 'TINYINT',
					'constraint' => 1,
					'default' => 0
				),
				'koken_instagram_id' => array(
					'type' => 'VARCHAR',
					'constraint' => 255,
					'null' => true
				),
				'koken_instagram_data' => array(
					'type' => 'TEXT',
					'null' => true
				),
			)
		);

		$this->register_filter('api.content', 'filter');
		$this->register_filter('site.templates', 'templates_filter');
		$this->register_hook('content.listing', 'listing');
	}

	function templates_filter($data)
	{
		$contents = false;
		foreach($data as $template)
		{
			if ($template['path'] === 'contents')
			{
				$contents = $template;
			}
		}

		if ($contents)
		{
			$contents['path'] = 'instagram';
			$contents['info']['template'] = 'contents';
			$contents['info']['name'] = 'Instagram';
			$contents['info']['icon'] = 'instagram';
			$contents['info']['description'] = 'Displays all imported Instagram images';
			$contents['info']['filters'] = array('koken_instagram=1');
			$data[] = $contents;
		}

		return $data;
	}

	function imported_ids()
	{
		$c = new Content;
		$existing = array();
		$existing_db = $c->where('koken_instagram', 1)->get_iterated();
		foreach($existing_db as $content)
		{
			$existing[] = $content->koken_instagram_id;
		}

		return $existing;
	}

	function auth($params)
	{
		if (isset($params['access_token']))
		{
			if ($params['access_token'] !== 'error')
			{
				$this->data->access_token = $params['access_token'];
				$this->data->user_id = $params['user'];
				$this->save_data();
			}

			$root = preg_replace('~/api\.php(.*)?$~', '', $_SERVER['SCRIPT_NAME']);

			$to = 'library/import/instagram';
			if (isset($params['to']) && $params['to'] === 'settings')
			{
				$to = 'settings/plugins/' . $this->get_key();
			}

			header("Location: $root/admin/#/" . $to);
			exit;
		}
		else
		{
			return array(
				'callback' => $_SERVER['QUERY_STRING'] . '/token:' . $this->request_token()
			);
		}
	}

	function revoke()
	{
		$this->data->access_token = null;
		$this->data->user_id = null;
		$this->save_data();
		exit;
	}

	function listing($content, $options)
	{
		if (isset($options['koken_instagram']))
		{
			$content->where('koken_instagram', (int) $options['koken_instagram']);
		}
	}

	function filter($data)
	{
		if ($data['koken_instagram'] > 0)
		{
			$data['filename'] = "Instagram";
		}

		if ($data['koken_instagram_captured'] > 0)
		{
			$data['captured_on']['utc'] = true;
		}

		if (!empty($data['koken_instagram_data']))
		{
			$instadata = unserialize($data['koken_instagram_data']);

			if ($instadata && isset($instadata['location']) && isset($instadata['location']['latitude']))
			{
				$data['geolocation'] = $instadata['location'];
			}
		}

		unset($data['koken_instagram']);
		unset($data['koken_instagram_id']);
		unset($data['koken_instagram_captured']);
		unset($data['koken_instagram_data']);

		return $data;
	}

	private function remove_emoji($string)
	{
		return preg_replace('/([0-9|#][\x{20E3}])|[\x{00ae}|\x{00a9}|\x{203C}|\x{2047}|\x{2048}|\x{2049}|\x{3030}|\x{303D}|\x{2139}|\x{2122}|\x{3297}|\x{3299}][\x{FE00}-\x{FEFF}]?|[\x{2190}-\x{21FF}][\x{FE00}-\x{FEFF}]?|[\x{2300}-\x{23FF}][\x{FE00}-\x{FEFF}]?|[\x{2460}-\x{24FF}][\x{FE00}-\x{FEFF}]?|[\x{25A0}-\x{25FF}][\x{FE00}-\x{FEFF}]?|[\x{2600}-\x{27BF}][\x{FE00}-\x{FEFF}]?|[\x{2900}-\x{297F}][\x{FE00}-\x{FEFF}]?|[\x{2B00}-\x{2BF0}][\x{FE00}-\x{FEFF}]?|[\x{1F000}-\x{1F6FF}][\x{FE00}-\x{FEFF}]?/u', '', $string);
	}

	function create_api()
	{
		$uploads = json_decode($_POST['payload']);
		$added = array();

		foreach($uploads as $upload)
		{
			$info = (array) $upload;

			if (isset($upload->caption->text))
			{
				$info['title'] = $upload->caption->text;
			}
			else
			{
				$info['title'] = '';
			}

			$info['width'] = $upload->images->standard_resolution->width;
			$info['height'] = $upload->images->standard_resolution->height;
			$info['location'] = (array) $upload->location;

			$result = $this->_create($info, $upload->images->standard_resolution->url, $upload->link);
			if ($result)
			{
				$added[] = $result;
			}
		}

		return $added;
	}

	function create()
	{
		if (!isset($_POST['url']))
		{
			return array('error' => 400, 'message' => 'Instagram URL not specified.');
		}

		$info = Shutter::get_oembed('https://api.instagram.com/oembed/?url=' . urldecode($_POST['url']));

		if ($info)
		{
			$result = $this->_create($info, $info['url'], $_POST['url']);
			if ($result)
			{
				return array('koken:redirect' => '/content/' . $result);
			}
			else
			{
				return array('error' => 500, 'message' => 'That Instagram image has already been added to your library.');
			}
		}
		else
		{
			return array('error' => 404, 'message' => 'Instagram image not found at that URL.');
		}
	}

	private function _create($info, $file, $source_url)
	{
		$c = new Content;

		$instagram_id = isset($info['media_id']) ? $info['media_id'] : $info['id'];

		$check = $c->where('koken_instagram_id', $instagram_id)->get();

		if ($check->exists())
		{
			return false;
		}
		else
		{
			$imports = array('width', 'height');

			$c->filename = basename(parse_url($file, PHP_URL_PATH));
			$c->file_modified_on = time();
			$c->koken_instagram = 1;
			$c->source = 'Instagram';
			$c->source_url = $source_url;
			$c->visibility = $_POST['visibility'];

			if (isset($info['created_time']))
			{
				$c->captured_on = $info['created_time'];
				$c->koken_instagram_captured = 1;
			}

			if (isset($info['location']) && !is_null($info['location']))
			{
				$c->koken_instagram_data = serialize(array('location' => $info['location']));
			}

			list($c->internal_id, $path) = $c->generate_internal_id();

			$this->download_file($file, $path . $c->filename);

			$tags = array();

			if ($this->data->autotag)
			{
				$tags[] = 'instagram';
			}

			if ($this->data->tags)
			{
				if (isset($info['tags']))
				{
					$tags = array_merge($tags, $info['tags']);
				}
				else
				{
					preg_match_all('/#([a-zA-Z0-9]+)/', $info['title'], $tags);
					if (count($tags) > 1 && count($tags[1]))
					{
						$tags = array_merge($tags, $tags[1]);
					}
				}
			}

			if ($this->data->title)
			{
				$imports[] = 'title';

				$info['title'] = $this->remove_emoji($info['title']);

				if ($this->data->strip_tags)
				{
					$info['title'] = trim(preg_replace('/#([a-zA-Z0-9]+)/', '', $info['title']));
				}

				if (strpos($info['title'], "\n") !== false)
				{
					$break = strpos($info['title'], "\n");
					$c->caption = trim(substr($info['title'], $break));
					$info['title'] = substr($info['title'], 0, $break);
				}
			}

			foreach($imports as $field)
			{
				$c->{$field} = $info[$field];
			}

			$c->koken_instagram_id = $instagram_id;

			return $c->create(array('tags' => $tags));
		}
	}
}