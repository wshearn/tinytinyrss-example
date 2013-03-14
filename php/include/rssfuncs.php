<?php
	define('DAEMON_UPDATE_LOGIN_LIMIT', 30);
	define('DAEMON_FEED_LIMIT', 100);
	define('DAEMON_SLEEP_INTERVAL', 60);

	function update_feedbrowser_cache($link) {

		$result = db_query($link, "SELECT feed_url, site_url, title, COUNT(id) AS subscribers
	  		FROM ttrss_feeds WHERE (SELECT COUNT(id) = 0 FROM ttrss_feeds AS tf
				WHERE tf.feed_url = ttrss_feeds.feed_url
				AND (private IS true OR auth_login != '' OR auth_pass != '' OR feed_url LIKE '%:%@%/%'))
				GROUP BY feed_url, site_url, title ORDER BY subscribers DESC LIMIT 1000");

		db_query($link, "BEGIN");

		db_query($link, "DELETE FROM ttrss_feedbrowser_cache");

		$count = 0;

		while ($line = db_fetch_assoc($result)) {
			$subscribers = db_escape_string($line["subscribers"]);
			$feed_url = db_escape_string($line["feed_url"]);
			$title = db_escape_string($line["title"]);
			$site_url = db_escape_string($line["site_url"]);

			$tmp_result = db_query($link, "SELECT subscribers FROM
				ttrss_feedbrowser_cache WHERE feed_url = '$feed_url'");

			if (db_num_rows($tmp_result) == 0) {

				db_query($link, "INSERT INTO ttrss_feedbrowser_cache
					(feed_url, site_url, title, subscribers) VALUES ('$feed_url',
						'$site_url', '$title', '$subscribers')");

				++$count;

			}

		}

		db_query($link, "COMMIT");

		return $count;

	}


	/**
	 * Update a feed batch.
	 * Used by daemons to update n feeds by run.
	 * Only update feed needing a update, and not being processed
	 * by another process.
	 *
	 * @param mixed $link Database link
	 * @param integer $limit Maximum number of feeds in update batch. Default to DAEMON_FEED_LIMIT.
	 * @param boolean $from_http Set to true if you call this function from http to disable cli specific code.
	 * @param boolean $debug Set to false to disable debug output. Default to true.
	 * @return void
	 */
	function update_daemon_common($link, $limit = DAEMON_FEED_LIMIT, $from_http = false, $debug = true) {
		// Process all other feeds using last_updated and interval parameters

		define('PREFS_NO_CACHE', true);

		// Test if the user has loggued in recently. If not, it does not update its feeds.
		if (!SINGLE_USER_MODE && DAEMON_UPDATE_LOGIN_LIMIT > 0) {
			if (DB_TYPE == "pgsql") {
				$login_thresh_qpart = "AND ttrss_users.last_login >= NOW() - INTERVAL '".DAEMON_UPDATE_LOGIN_LIMIT." days'";
			} else {
				$login_thresh_qpart = "AND ttrss_users.last_login >= DATE_SUB(NOW(), INTERVAL ".DAEMON_UPDATE_LOGIN_LIMIT." DAY)";
			}
		} else {
			$login_thresh_qpart = "";
		}

		// Test if the feed need a update (update interval exceded).
		if (DB_TYPE == "pgsql") {
			$update_limit_qpart = "AND ((
					ttrss_feeds.update_interval = 0
					AND ttrss_feeds.last_updated < NOW() - CAST((ttrss_user_prefs.value || ' minutes') AS INTERVAL)
				) OR (
					ttrss_feeds.update_interval > 0
					AND ttrss_feeds.last_updated < NOW() - CAST((ttrss_feeds.update_interval || ' minutes') AS INTERVAL)
				) OR ttrss_feeds.last_updated IS NULL
				OR last_updated = '1970-01-01 00:00:00')";
		} else {
			$update_limit_qpart = "AND ((
					ttrss_feeds.update_interval = 0
					AND ttrss_feeds.last_updated < DATE_SUB(NOW(), INTERVAL CONVERT(ttrss_user_prefs.value, SIGNED INTEGER) MINUTE)
				) OR (
					ttrss_feeds.update_interval > 0
					AND ttrss_feeds.last_updated < DATE_SUB(NOW(), INTERVAL ttrss_feeds.update_interval MINUTE)
				) OR ttrss_feeds.last_updated IS NULL
				OR last_updated = '1970-01-01 00:00:00')";
		}

		// Test if feed is currently being updated by another process.
		if (DB_TYPE == "pgsql") {
			$updstart_thresh_qpart = "AND (ttrss_feeds.last_update_started IS NULL OR ttrss_feeds.last_update_started < NOW() - INTERVAL '5 minutes')";
		} else {
			$updstart_thresh_qpart = "AND (ttrss_feeds.last_update_started IS NULL OR ttrss_feeds.last_update_started < DATE_SUB(NOW(), INTERVAL 5 MINUTE))";
		}

		// Test if there is a limit to number of updated feeds
		$query_limit = "";
		if($limit) $query_limit = sprintf("LIMIT %d", $limit);

		$random_qpart = sql_random_function();

		// We search for feed needing update.
		$result = db_query($link, "SELECT ttrss_feeds.feed_url,ttrss_feeds.id, ttrss_feeds.owner_uid,
				".SUBSTRING_FOR_DATE."(ttrss_feeds.last_updated,1,19) AS last_updated,
				ttrss_feeds.update_interval
			FROM
				ttrss_feeds, ttrss_users, ttrss_user_prefs
			WHERE
				ttrss_feeds.owner_uid = ttrss_users.id
				AND ttrss_users.id = ttrss_user_prefs.owner_uid
				AND ttrss_user_prefs.pref_name = 'DEFAULT_UPDATE_INTERVAL'
				$login_thresh_qpart $update_limit_qpart
			 $updstart_thresh_qpart
			ORDER BY $random_qpart $query_limit");

		$user_prefs_cache = array();

		if($debug) _debug(sprintf("Scheduled %d feeds to update...\n", db_num_rows($result)));

		// Here is a little cache magic in order to minimize risk of double feed updates.
		$feeds_to_update = array();
		while ($line = db_fetch_assoc($result)) {
			$feeds_to_update[$line['id']] = $line;
		}

		// We update the feed last update started date before anything else.
		// There is no lag due to feed contents downloads
		// It prevent an other process to update the same feed.
		$feed_ids = array_keys($feeds_to_update);
		if($feed_ids) {
			db_query($link, sprintf("UPDATE ttrss_feeds SET last_update_started = NOW()
				WHERE id IN (%s)", implode(',', $feed_ids)));
		}

		expire_cached_files($debug);
		expire_lock_files($debug);

		// For each feed, we call the feed update function.
		while ($line = array_pop($feeds_to_update)) {

			if($debug) _debug("Feed: " . $line["feed_url"] . ", " . $line["last_updated"]);

			update_rss_feed($link, $line["id"], true);

			sleep(1); // prevent flood (FIXME make this an option?)
		}

		require_once "digest.php";

		// Send feed digests by email if needed.
		send_headlines_digests($link, $debug);

	} // function update_daemon_common

	// ignore_daemon is not used
	function update_rss_feed($link, $feed, $ignore_daemon = false, $no_cache = false,
		$override_url = false) {

		require_once "lib/simplepie/simplepie.inc";

		$debug_enabled = defined('DAEMON_EXTENDED_DEBUG') || $_REQUEST['xdebug'];

		if ($debug_enabled) {
			_debug("update_rss_feed: start");
		}

		$result = db_query($link, "SELECT id,update_interval,auth_login,
			feed_url,auth_pass,cache_images,last_updated,
			mark_unread_on_update, owner_uid,
			pubsub_state
			FROM ttrss_feeds WHERE id = '$feed'");

		if (db_num_rows($result) == 0) {
			if ($debug_enabled) {
				_debug("update_rss_feed: feed $feed NOT FOUND/SKIPPED");
			}
			return false;
		}

		$last_updated = db_fetch_result($result, 0, "last_updated");
		$owner_uid = db_fetch_result($result, 0, "owner_uid");
		$mark_unread_on_update = sql_bool_to_bool(db_fetch_result($result,
			0, "mark_unread_on_update"));
		$pubsub_state = db_fetch_result($result, 0, "pubsub_state");

		db_query($link, "UPDATE ttrss_feeds SET last_update_started = NOW()
			WHERE id = '$feed'");

		$auth_login = db_fetch_result($result, 0, "auth_login");
		$auth_pass = db_fetch_result($result, 0, "auth_pass");

		$cache_images = sql_bool_to_bool(db_fetch_result($result, 0, "cache_images"));
		$fetch_url = db_fetch_result($result, 0, "feed_url");

		$feed = db_escape_string($feed);

		/* if ($auth_login && $auth_pass ){
			$url_parts = array();
			preg_match("/(^[^:]*):\/\/(.*)/", $fetch_url, $url_parts);

			if ($url_parts[1] && $url_parts[2]) {
				$fetch_url = $url_parts[1] . "://$auth_login:$auth_pass@" . $url_parts[2];
			}
		} */

		if ($override_url)
			$fetch_url = $override_url;

		if ($debug_enabled) {
			_debug("update_rss_feed: fetching [$fetch_url]...");
		}

		// Ignore cache if new feed or manual update.
		$cache_age = (is_null($last_updated) || $last_updated == '1970-01-01 00:00:00') ?
			-1 : get_feed_update_interval($link, $feed) * 60;

		$simplepie_cache_dir = CACHE_DIR . "/simplepie";

		if (!is_dir($simplepie_cache_dir)) {
			mkdir($simplepie_cache_dir);
		}

		$feed_data = fetch_file_contents($fetch_url, false,
			$auth_login, $auth_pass, false, $no_cache ? 15 : 45);

		if (!$feed_data) {
			global $fetch_last_error;

			if ($debug_enabled) {
				_debug("update_rss_feed: unable to fetch: $fetch_last_error");
			}

			$error_escaped = db_escape_string($fetch_last_error);

			db_query($link,
				"UPDATE ttrss_feeds SET last_error = '$error_escaped',
					last_updated = NOW() WHERE id = '$feed'");

			return;
		}

		$pluginhost = new PluginHost($link);
		$pluginhost->set_debug($debug_enabled);
		$user_plugins = get_pref($link, "_ENABLED_PLUGINS", $owner_uid);

		$pluginhost->load(PLUGINS, $pluginhost::KIND_ALL);
		$pluginhost->load($user_plugins, $pluginhost::KIND_USER, $owner_uid);
		$pluginhost->load_data();

		foreach ($pluginhost->get_hooks($pluginhost::HOOK_FEED_FETCHED) as $plugin) {
			$feed_data = $plugin->hook_feed_fetched($feed_data);
		}

		if ($debug_enabled) {
			_debug("update_rss_feed: fetch done, parsing...");
		}

		$rss = new SimplePie();
		$rss->set_sanitize_class("SanitizeDummy");
		// simplepie ignores the above and creates default sanitizer anyway,
		// so let's override it...
		$rss->sanitize = new SanitizeDummy();
		$rss->set_output_encoding('UTF-8');
		$rss->set_raw_data($feed_data);

		if ($debug_enabled) {
			_debug("feed update interval (sec): " .
				get_feed_update_interval($link, $feed)*60);
		}

		$rss->enable_cache(!$no_cache);

		if (!$no_cache) {
			$rss->set_cache_location($simplepie_cache_dir);
			$rss->set_cache_duration($cache_age);
		}

		@$rss->init();

//		print_r($rss);

		$feed = db_escape_string($feed);

		if (!$rss->error()) {

			// We use local pluginhost here because we need to load different per-user feed plugins
			$pluginhost->run_hooks($pluginhost::HOOK_FEED_PARSED, "hook_feed_parsed", $rss);

			if ($debug_enabled) {
				_debug("update_rss_feed: processing feed data...");
			}

//			db_query($link, "BEGIN");

			if (DB_TYPE == "pgsql") {
				$favicon_interval_qpart = "favicon_last_checked < NOW() - INTERVAL '12 hour'";
			} else {
				$favicon_interval_qpart = "favicon_last_checked < DATE_SUB(NOW(), INTERVAL 12 HOUR)";
			}

			$result = db_query($link, "SELECT title,site_url,owner_uid,
				(favicon_last_checked IS NULL OR $favicon_interval_qpart) AS
						favicon_needs_check
				FROM ttrss_feeds WHERE id = '$feed'");

			$registered_title = db_fetch_result($result, 0, "title");
			$orig_site_url = db_fetch_result($result, 0, "site_url");
			$favicon_needs_check = sql_bool_to_bool(db_fetch_result($result, 0,
				"favicon_needs_check"));

			$owner_uid = db_fetch_result($result, 0, "owner_uid");

			$site_url = db_escape_string(mb_substr(rewrite_relative_url($fetch_url, $rss->get_link()), 0, 245));

			if ($debug_enabled) {
				_debug("update_rss_feed: checking favicon...");
			}

			if ($favicon_needs_check) {
				check_feed_favicon($site_url, $feed, $link);

				db_query($link, "UPDATE ttrss_feeds SET favicon_last_checked = NOW()
					WHERE id = '$feed'");
			}

			if (!$registered_title || $registered_title == "[Unknown]") {

				$feed_title = db_escape_string($rss->get_title());

				if ($debug_enabled) {
					_debug("update_rss_feed: registering title: $feed_title");
				}

				db_query($link, "UPDATE ttrss_feeds SET
					title = '$feed_title' WHERE id = '$feed'");
			}

			if ($site_url && $orig_site_url != $site_url) {
				db_query($link, "UPDATE ttrss_feeds SET
					site_url = '$site_url' WHERE id = '$feed'");
			}

			if ($debug_enabled) {
				_debug("update_rss_feed: loading filters & labels...");
			}

			$filters = load_filters($link, $feed, $owner_uid);
			$labels = get_all_labels($link, $owner_uid);

			if ($debug_enabled) {
				//print_r($filters);
				_debug("update_rss_feed: " . count($filters) . " filters loaded.");
			}

			$items = $rss->get_items();

			if (!is_array($items)) {
				if ($debug_enabled) {
					_debug("update_rss_feed: no articles found.");
				}

				db_query($link, "UPDATE ttrss_feeds
					SET last_updated = NOW(), last_error = '' WHERE id = '$feed'");

				return; // no articles
			}

			if ($pubsub_state != 2 && PUBSUBHUBBUB_ENABLED) {

				if ($debug_enabled) _debug("update_rss_feed: checking for PUSH hub...");

				$feed_hub_url = false;

				$links = $rss->get_links('hub');

				if ($links && is_array($links)) {
					foreach ($links as $l) {
						$feed_hub_url = $l;
						break;
					}
				}

				if ($debug_enabled) _debug("update_rss_feed: feed hub url: $feed_hub_url");

				if ($feed_hub_url && function_exists('curl_init') &&
					!ini_get("open_basedir")) {

					require_once 'lib/pubsubhubbub/subscriber.php';

					$callback_url = get_self_url_prefix() .
						"/public.php?op=pubsub&id=$feed";

					$s = new Subscriber($feed_hub_url, $callback_url);

					$rc = $s->subscribe($fetch_url);

					if ($debug_enabled)
						_debug("update_rss_feed: feed hub url found, subscribe request sent.");

					db_query($link, "UPDATE ttrss_feeds SET pubsub_state = 1
						WHERE id = '$feed'");
				}
			}

			if ($debug_enabled) {
				_debug("update_rss_feed: processing articles...");
			}

			foreach ($items as $item) {
				if ($_REQUEST['xdebug'] == 3) {
					print_r($item);
				}

				$entry_guid = $item->get_id();
				if (!$entry_guid) $entry_guid = $item->get_link();
				if (!$entry_guid) $entry_guid = make_guid_from_title($item->get_title());

				if ($debug_enabled) {
					_debug("update_rss_feed: guid $entry_guid");
				}

				if (!$entry_guid) continue;

				$entry_guid = "$owner_uid,$entry_guid";

				$entry_timestamp = "";

				$entry_timestamp = strtotime($item->get_date());

				if ($entry_timestamp == -1 || !$entry_timestamp) {
					$entry_timestamp = time();
					$no_orig_date = 'true';
				} else {
					$no_orig_date = 'false';
				}

				$entry_timestamp_fmt = strftime("%Y/%m/%d %H:%M:%S", $entry_timestamp);

				if ($debug_enabled) {
					_debug("update_rss_feed: date $entry_timestamp [$entry_timestamp_fmt]");
				}

				$entry_title = $item->get_title();

				$entry_link = rewrite_relative_url($site_url, $item->get_link());

				if ($debug_enabled) {
					_debug("update_rss_feed: title $entry_title");
					_debug("update_rss_feed: link $entry_link");
				}

				if (!$entry_title) $entry_title = date("Y-m-d H:i:s", $entry_timestamp);;

				$entry_content = $item->get_content();
				if (!$entry_content) $entry_content = $item->get_description();

				if ($_REQUEST["xdebug"] == 2) {
					print "update_rss_feed: content: ";
					print $entry_content;
					print "\n";
				}

				$entry_comments = $item->data["comments"];

				if ($item->get_author()) {
					$entry_author_item = $item->get_author();
					$entry_author = $entry_author_item->get_name();
					if (!$entry_author) $entry_author = $entry_author_item->get_email();

					$entry_author = db_escape_string($entry_author);
				}

				$entry_guid = db_escape_string(mb_substr($entry_guid, 0, 245));

				$entry_comments = db_escape_string(mb_substr($entry_comments, 0, 245));
				$entry_author = db_escape_string(mb_substr($entry_author, 0, 245));

				$num_comments = $item->get_item_tags('http://purl.org/rss/1.0/modules/slash/', 'comments');

				if (is_array($num_comments) && is_array($num_comments[0])) {
					$num_comments = (int) $num_comments[0]["data"];
				} else {
					$num_comments = 0;
				}

				if ($debug_enabled) {
					_debug("update_rss_feed: num_comments: $num_comments");
					_debug("update_rss_feed: looking for tags [1]...");
				}

				// parse <category> entries into tags

				$additional_tags = array();

				$additional_tags_src = $item->get_categories();

				if (is_array($additional_tags_src)) {
					foreach ($additional_tags_src as $tobj) {
						array_push($additional_tags, $tobj->get_term());
					}
				}

				if ($debug_enabled) {
					_debug("update_rss_feed: category tags:");
					print_r($additional_tags);
				}

				if ($debug_enabled) {
					_debug("update_rss_feed: looking for tags [2]...");
				}

				$entry_tags = array_unique($additional_tags);

				for ($i = 0; $i < count($entry_tags); $i++)
					$entry_tags[$i] = mb_strtolower($entry_tags[$i], 'utf-8');

				if ($debug_enabled) {
					//_debug("update_rss_feed: unfiltered tags found:");
					//print_r($entry_tags);
				}

				if ($debug_enabled) {
					_debug("update_rss_feed: done collecting data.");
				}

				// TODO: less memory-hungry implementation

				if ($debug_enabled) {
					_debug("update_rss_feed: applying plugin filters..");
				}

				// FIXME not sure if owner_uid is a good idea here, we may have a base entry without user entry (?)
				$result = db_query($link, "SELECT plugin_data,title,content,link,tag_cache,author FROM ttrss_entries, ttrss_user_entries
					WHERE ref_id = id AND guid = '".db_escape_string($entry_guid)."' AND owner_uid = $owner_uid");

				if (db_num_rows($result) != 0) {
					$entry_plugin_data = db_fetch_result($result, 0, "plugin_data");
					$stored_article = array("title" => db_fetch_result($result, 0, "title"),
						"content" => db_fetch_result($result, 0, "content"),
						"link" => db_fetch_result($result, 0, "link"),
						"tags" => explode(",", db_fetch_result($result, 0, "tag_cache")),
						"author" => db_fetch_result($result, 0, "author"));
				} else {
					$entry_plugin_data = "";
					$stored_article = array();
				}

				$article = array("owner_uid" => $owner_uid, // read only
					"guid" => $entry_guid, // read only
					"title" => $entry_title,
					"content" => $entry_content,
					"link" => $entry_link,
					"tags" => $entry_tags,
					"plugin_data" => $entry_plugin_data,
					"author" => $entry_author,
					"stored" => $stored_article);

				foreach ($pluginhost->get_hooks($pluginhost::HOOK_ARTICLE_FILTER) as $plugin) {
					$article = $plugin->hook_article_filter($article);
				}

				$entry_tags = $article["tags"];
				$entry_guid = db_escape_string($entry_guid);
				$entry_content = db_escape_string($article["content"], false);
				$entry_title = db_escape_string($article["title"]);
				$entry_author = db_escape_string($article["author"]);
				$entry_link = db_escape_string($article["link"]);
				$entry_plugin_data = db_escape_string($article["plugin_data"]);

				if ($debug_enabled) {
					_debug("update_rss_feed: plugin data: $entry_plugin_data");
				}

				if ($cache_images && is_writable(CACHE_DIR . '/images'))
					$entry_content = cache_images($entry_content, $site_url, $debug_enabled);

				$content_hash = "SHA1:" . sha1($entry_content);

				db_query($link, "BEGIN");

				$result = db_query($link, "SELECT id FROM	ttrss_entries
					WHERE guid = '$entry_guid'");

				if (db_num_rows($result) == 0) {

					if ($debug_enabled) {
						_debug("update_rss_feed: base guid [$entry_guid] not found");
					}

					// base post entry does not exist, create it

					$result = db_query($link,
						"INSERT INTO ttrss_entries
							(title,
							guid,
							link,
							updated,
							content,
							content_hash,
							cached_content,
							no_orig_date,
							date_updated,
							date_entered,
							comments,
							num_comments,
							plugin_data,
							author)
						VALUES
							('$entry_title',
							'$entry_guid',
							'$entry_link',
							'$entry_timestamp_fmt',
							'$entry_content',
							'$content_hash',
							'',
							$no_orig_date,
							NOW(),
							NOW(),
							'$entry_comments',
							'$num_comments',
							'$entry_plugin_data',
							'$entry_author')");

					$article_labels = array();

				} else {
					// we keep encountering the entry in feeds, so we need to
					// update date_updated column so that we don't get horrible
					// dupes when the entry gets purged and reinserted again e.g.
					// in the case of SLOW SLOW OMG SLOW updating feeds

					$base_entry_id = db_fetch_result($result, 0, "id");

					db_query($link, "UPDATE ttrss_entries SET date_updated = NOW()
						WHERE id = '$base_entry_id'");

					$article_labels = get_article_labels($link, $base_entry_id, $owner_uid);
				}

				// now it should exist, if not - bad luck then

				$result = db_query($link, "SELECT
						id,content_hash,no_orig_date,title,plugin_data,
						".SUBSTRING_FOR_DATE."(date_updated,1,19) as date_updated,
						".SUBSTRING_FOR_DATE."(updated,1,19) as updated,
						num_comments
					FROM
						ttrss_entries
					WHERE guid = '$entry_guid'");

				$entry_ref_id = 0;
				$entry_int_id = 0;

				if (db_num_rows($result) == 1) {

					if ($debug_enabled) {
						_debug("update_rss_feed: base guid [$entry_guid] found, checking for user record");
					}

					// this will be used below in update handler
					$orig_content_hash = db_fetch_result($result, 0, "content_hash");
					$orig_title = db_fetch_result($result, 0, "title");
					$orig_num_comments = db_fetch_result($result, 0, "num_comments");
					$orig_date_updated = strtotime(db_fetch_result($result,
						0, "date_updated"));
					$orig_plugin_data = db_fetch_result($result, 0, "plugin_data");

					$ref_id = db_fetch_result($result, 0, "id");
					$entry_ref_id = $ref_id;

					// check for user post link to main table

					// do we allow duplicate posts with same GUID in different feeds?
					if (get_pref($link, "ALLOW_DUPLICATE_POSTS", $owner_uid, false)) {
						$dupcheck_qpart = "AND (feed_id = '$feed' OR feed_id IS NULL)";
					} else {
						$dupcheck_qpart = "";
					}

					/* Collect article tags here so we could filter by them: */

					$article_filters = get_article_filters($filters, $entry_title,
						$entry_content, $entry_link, $entry_timestamp, $entry_author,
						$entry_tags);

					if ($debug_enabled) {
						_debug("update_rss_feed: article filters: ");
						if (count($article_filters) != 0) {
							print_r($article_filters);
						}
					}

					if (find_article_filter($article_filters, "filter")) {
						db_query($link, "COMMIT"); // close transaction in progress
						continue;
					}

					$score = calculate_article_score($article_filters);

					if ($debug_enabled) {
						_debug("update_rss_feed: initial score: $score");
					}

					$query = "SELECT ref_id, int_id FROM ttrss_user_entries WHERE
							ref_id = '$ref_id' AND owner_uid = '$owner_uid'
							$dupcheck_qpart";

//					if ($_REQUEST["xdebug"]) print "$query\n";

					$result = db_query($link, $query);

					// okay it doesn't exist - create user entry
					if (db_num_rows($result) == 0) {

						if ($debug_enabled) {
							_debug("update_rss_feed: user record not found, creating...");
						}

						if ($score >= -500 && !find_article_filter($article_filters, 'catchup')) {
							$unread = 'true';
							$last_read_qpart = 'NULL';
						} else {
							$unread = 'false';
							$last_read_qpart = 'NOW()';
						}

						if (find_article_filter($article_filters, 'mark') || $score > 1000) {
							$marked = 'true';
						} else {
							$marked = 'false';
						}

						if (find_article_filter($article_filters, 'publish')) {
							$published = 'true';
						} else {
							$published = 'false';
						}

						// N-grams

						if (DB_TYPE == "pgsql" and defined('_NGRAM_TITLE_DUPLICATE_THRESHOLD')) {

							$result = db_query($link, "SELECT COUNT(*) AS similar FROM
									ttrss_entries,ttrss_user_entries
								WHERE ref_id = id AND updated >= NOW() - INTERVAL '7 day'
									AND similarity(title, '$entry_title') >= "._NGRAM_TITLE_DUPLICATE_THRESHOLD."
									AND owner_uid = $owner_uid");

							$ngram_similar = db_fetch_result($result, 0, "similar");

							if ($debug_enabled) {
								_debug("update_rss_feed: N-gram similar results: $ngram_similar");
							}

							if ($ngram_similar > 0) {
								$unread = 'false';
							}
						}

						$result = db_query($link,
							"INSERT INTO ttrss_user_entries
								(ref_id, owner_uid, feed_id, unread, last_read, marked,
									published, score, tag_cache, label_cache, uuid)
							VALUES ('$ref_id', '$owner_uid', '$feed', $unread,
								$last_read_qpart, $marked, $published, '$score', '', '', '')");

						if (PUBSUBHUBBUB_HUB && $published == 'true') {
							$rss_link = get_self_url_prefix() .
								"/public.php?op=rss&id=-2&key=" .
								get_feed_access_key($link, -2, false, $owner_uid);

							$p = new Publisher(PUBSUBHUBBUB_HUB);

							$pubsub_result = $p->publish_update($rss_link);
						}

						$result = db_query($link,
							"SELECT int_id FROM ttrss_user_entries WHERE
								ref_id = '$ref_id' AND owner_uid = '$owner_uid' AND
								feed_id = '$feed' LIMIT 1");

						if (db_num_rows($result) == 1) {
							$entry_int_id = db_fetch_result($result, 0, "int_id");
						}
					} else {
						if ($debug_enabled) {
							_debug("update_rss_feed: user record FOUND");
						}

						$entry_ref_id = db_fetch_result($result, 0, "ref_id");
						$entry_int_id = db_fetch_result($result, 0, "int_id");
					}

					if ($debug_enabled) {
						_debug("update_rss_feed: RID: $entry_ref_id, IID: $entry_int_id");
					}

					$post_needs_update = false;
					$update_insignificant = false;

					if ($orig_num_comments != $num_comments) {
						$post_needs_update = true;
						$update_insignificant = true;
					}

					if ($entry_plugin_data != $orig_plugin_data) {
						$post_needs_update = true;
						$update_insignificant = true;
					}

					if ($content_hash != $orig_content_hash) {
						$post_needs_update = true;
						$update_insignificant = false;
					}

					if (db_escape_string($orig_title) != $entry_title) {
						$post_needs_update = true;
						$update_insignificant = false;
					}

					// if post needs update, update it and mark all user entries
					// linking to this post as updated
					if ($post_needs_update) {

						if (defined('DAEMON_EXTENDED_DEBUG')) {
							_debug("update_rss_feed: post $entry_guid needs update...");
						}

//						print "<!-- post $orig_title needs update : $post_needs_update -->";

						db_query($link, "UPDATE ttrss_entries
							SET title = '$entry_title', content = '$entry_content',
								content_hash = '$content_hash',
								updated = '$entry_timestamp_fmt',
								num_comments = '$num_comments',
								plugin_data = '$entry_plugin_data'
							WHERE id = '$ref_id'");

						if (!$update_insignificant) {
							if ($mark_unread_on_update) {
								db_query($link, "UPDATE ttrss_user_entries
									SET last_read = null, unread = true WHERE ref_id = '$ref_id'");
							}
						}
					}
				}

				db_query($link, "COMMIT");

				if ($debug_enabled) {
					_debug("update_rss_feed: assigning labels...");
				}

				assign_article_to_label_filters($link, $entry_ref_id, $article_filters,
					$owner_uid, $article_labels);

				if ($debug_enabled) {
					_debug("update_rss_feed: looking for enclosures...");
				}

				// enclosures

				$enclosures = array();

				$encs = $item->get_enclosures();

				if (is_array($encs)) {
					foreach ($encs as $e) {
						$e_item = array(
							$e->link, $e->type, $e->length);
						array_push($enclosures, $e_item);
					}
				}

				if ($debug_enabled) {
					_debug("update_rss_feed: article enclosures:");
					print_r($enclosures);
				}

				db_query($link, "BEGIN");

				foreach ($enclosures as $enc) {
					$enc_url = db_escape_string($enc[0]);
					$enc_type = db_escape_string($enc[1]);
					$enc_dur = db_escape_string($enc[2]);

					$result = db_query($link, "SELECT id FROM ttrss_enclosures
						WHERE content_url = '$enc_url' AND post_id = '$entry_ref_id'");

					if (db_num_rows($result) == 0) {
						db_query($link, "INSERT INTO ttrss_enclosures
							(content_url, content_type, title, duration, post_id) VALUES
							('$enc_url', '$enc_type', '', '$enc_dur', '$entry_ref_id')");
					}
				}

				db_query($link, "COMMIT");

				// check for manual tags (we have to do it here since they're loaded from filters)

				foreach ($article_filters as $f) {
					if ($f["type"] == "tag") {

						$manual_tags = trim_array(explode(",", $f["param"]));

						foreach ($manual_tags as $tag) {
							if (tag_is_valid($tag)) {
								array_push($entry_tags, $tag);
							}
						}
					}
				}

				// Skip boring tags

				$boring_tags = trim_array(explode(",", mb_strtolower(get_pref($link,
					'BLACKLISTED_TAGS', $owner_uid, ''), 'utf-8')));

				$filtered_tags = array();
				$tags_to_cache = array();

				if ($entry_tags && is_array($entry_tags)) {
					foreach ($entry_tags as $tag) {
						if (array_search($tag, $boring_tags) === false) {
							array_push($filtered_tags, $tag);
						}
					}
				}

				$filtered_tags = array_unique($filtered_tags);

				if ($debug_enabled) {
					_debug("update_rss_feed: filtered article tags:");
					print_r($filtered_tags);
				}

				// Save article tags in the database

				if (count($filtered_tags) > 0) {

					db_query($link, "BEGIN");

					foreach ($filtered_tags as $tag) {

						$tag = sanitize_tag($tag);
						$tag = db_escape_string($tag);

						if (!tag_is_valid($tag)) continue;

						$result = db_query($link, "SELECT id FROM ttrss_tags
							WHERE tag_name = '$tag' AND post_int_id = '$entry_int_id' AND
							owner_uid = '$owner_uid' LIMIT 1");

							if ($result && db_num_rows($result) == 0) {

								db_query($link, "INSERT INTO ttrss_tags
									(owner_uid,tag_name,post_int_id)
									VALUES ('$owner_uid','$tag', '$entry_int_id')");
							}

						array_push($tags_to_cache, $tag);
					}

					/* update the cache */

					$tags_to_cache = array_unique($tags_to_cache);

					$tags_str = db_escape_string(join(",", $tags_to_cache));

					db_query($link, "UPDATE ttrss_user_entries
						SET tag_cache = '$tags_str' WHERE ref_id = '$entry_ref_id'
						AND owner_uid = $owner_uid");

					db_query($link, "COMMIT");
				}

				if (get_pref($link, "AUTO_ASSIGN_LABELS", $owner_uid, false)) {
					if ($debug_enabled) {
						_debug("update_rss_feed: auto-assigning labels...");
					}

					foreach ($labels as $label) {
						$caption = $label["caption"];

						if (preg_match("/\b$caption\b/i", "$tags_str " . strip_tags($entry_content) . " $entry_title")) {
							if (!labels_contains_caption($article_labels, $caption)) {
								label_add_article($link, $entry_ref_id, $caption, $owner_uid);
							}
						}
					}
				}

				if ($debug_enabled) {
					_debug("update_rss_feed: article processed");
				}
			}

			if (!$last_updated) {
				if ($debug_enabled) {
					_debug("update_rss_feed: new feed, catching it up...");
				}
				catchup_feed($link, $feed, false, $owner_uid);
			}

			if ($debug_enabled) {
				_debug("purging feed...");
			}

			purge_feed($link, $feed, 0, $debug_enabled);

			db_query($link, "UPDATE ttrss_feeds
				SET last_updated = NOW(), last_error = '' WHERE id = '$feed'");

//			db_query($link, "COMMIT");

		} else {

			$error_msg = db_escape_string(mb_substr($rss->error(), 0, 245));

			if ($debug_enabled) {
				_debug("update_rss_feed: error fetching feed: $error_msg");
			}

			db_query($link,
				"UPDATE ttrss_feeds SET last_error = '$error_msg',
					last_updated = NOW() WHERE id = '$feed'");
		}

		unset($rss);

		if ($debug_enabled) {
			_debug("update_rss_feed: done");
		}

	}

	function cache_images($html, $site_url, $debug) {
		$cache_dir = CACHE_DIR . "/images";

		libxml_use_internal_errors(true);

		$charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

		$doc = new DOMDocument();
		$doc->loadHTML($charset_hack . $html);
		$xpath = new DOMXPath($doc);

		$entries = $xpath->query('(//img[@src])');

		foreach ($entries as $entry) {
			if ($entry->hasAttribute('src')) {
				$src = rewrite_relative_url($site_url, $entry->getAttribute('src'));

				$local_filename = CACHE_DIR . "/images/" . sha1($src) . ".png";

				if ($debug) _debug("cache_images: downloading: $src to $local_filename");

				if (!file_exists($local_filename)) {
					$file_content = fetch_file_contents($src);

					if ($file_content && strlen($file_content) > 1024) {
						file_put_contents($local_filename, $file_content);
					}
				}

				if (file_exists($local_filename)) {
					$entry->setAttribute('src', SELF_URL_PATH . '/image.php?url=' .
						base64_encode($src));
				}
			}
		}

		$node = $doc->getElementsByTagName('body')->item(0);

		return $doc->saveXML($node, LIBXML_NOEMPTYTAG);
	}

	function expire_lock_files($debug) {
		if ($debug) _debug("Removing old lock files...");

		$num_deleted = 0;

		if (is_writable(LOCK_DIRECTORY)) {
			$files = glob(LOCK_DIRECTORY . "/*.lock");

			if ($files) {
				foreach ($files as $file) {
					if (!file_is_locked($file) && time() - filemtime($file) > 86400*2) {
						unlink($file);
						++$num_deleted;
					}
				}
			}
		}

		if ($debug) _debug("Removed $num_deleted files.");
	}

	function expire_cached_files($debug) {
		foreach (array("simplepie", "images", "export") as $dir) {
			$cache_dir = CACHE_DIR . "/$dir";

			if ($debug) _debug("Expiring $cache_dir");

			$num_deleted = 0;

			if (is_writable($cache_dir)) {
				$files = glob("$cache_dir/*");

				if ($files) {
					foreach ($files as $file) {
						if (time() - filemtime($file) > 86400*7) {
							unlink($file);

							++$num_deleted;
						}
					}
				}
			}

			if ($debug) _debug("Removed $num_deleted files.");
		}
	}

	/**
	* Source: http://www.php.net/manual/en/function.parse-url.php#104527
	* Returns the url query as associative array
	*
	* @param    string    query
	* @return    array    params
	*/
	function convertUrlQuery($query) {
		$queryParts = explode('&', $query);

		$params = array();

		foreach ($queryParts as $param) {
			$item = explode('=', $param);
			$params[$item[0]] = $item[1];
		}

		return $params;
	}

	function get_article_filters($filters, $title, $content, $link, $timestamp, $author, $tags) {
		$matches = array();

		foreach ($filters as $filter) {
			$match_any_rule = $filter["match_any_rule"];
			$filter_match = false;

			foreach ($filter["rules"] as $rule) {
				$match = false;
				$reg_exp = $rule["reg_exp"];

				if (!$reg_exp)
					continue;

				switch ($rule["type"]) {
				case "title":
					$match = @preg_match("/$reg_exp/i", $title);
					break;
				case "content":
					// we don't need to deal with multiline regexps
					$content = preg_replace("/[\r\n\t]/", "", $content);

					$match = @preg_match("/$reg_exp/i", $content);
					break;
				case "both":
					// we don't need to deal with multiline regexps
					$content = preg_replace("/[\r\n\t]/", "", $content);

					$match = (@preg_match("/$reg_exp/i", $title) || @preg_match("/$reg_exp/i", $content));
					break;
				case "link":
					$match = @preg_match("/$reg_exp/i", $link);
					break;
				case "author":
					$match = @preg_match("/$reg_exp/i", $author);
					break;
				case "tag":
					$tag_string = join(",", $tags);
					$match = @preg_match("/$reg_exp/i", $tag_string);
					break;
				}

				if ($match_any_rule) {
					if ($match) {
						$filter_match = true;
						break;
					}
				} else {
					$filter_match = $match;
					if (!$match) {
						break;
					}
				}
			}

			if ($filter_match) {
				foreach ($filter["actions"] AS $action) {
					array_push($matches, $action);
				}
			}
		}

		return $matches;
	}

	function find_article_filter($filters, $filter_name) {
		foreach ($filters as $f) {
			if ($f["type"] == $filter_name) {
				return $f;
			};
		}
		return false;
	}

	function find_article_filters($filters, $filter_name) {
		$results = array();

		foreach ($filters as $f) {
			if ($f["type"] == $filter_name) {
				array_push($results, $f);
			};
		}
		return $results;
	}

	function calculate_article_score($filters) {
		$score = 0;

		foreach ($filters as $f) {
			if ($f["type"] == "score") {
				$score += $f["param"];
			};
		}
		return $score;
	}

	function labels_contains_caption($labels, $caption) {
		foreach ($labels as $label) {
			if ($label[1] == $caption) {
				return true;
			}
		}

		return false;
	}

	function assign_article_to_label_filters($link, $id, $filters, $owner_uid, $article_labels) {
		foreach ($filters as $f) {
			if ($f["type"] == "label") {
				if (!labels_contains_caption($article_labels, $f["param"])) {
					label_add_article($link, $id, $f["param"], $owner_uid);
				}
			}
		}
	}

	function cache_content($link, $url, $login, $pass) {

		$content = fetch_file_contents($url, $login, $pass);

		if ($content) {
			$doc = new DOMDocument();
			@$doc->loadHTML($content);
			$xpath = new DOMXPath($doc);

			$node = $doc->getElementsByTagName('body')->item(0);

			if ($node) {
				$content = $doc->saveXML($node, LIBXML_NOEMPTYTAG);

				return $content;
			}
		}

		return "";
	}

	function make_guid_from_title($title) {
		return preg_replace("/[ \"\',.:;]/", "-",
			mb_strtolower(strip_tags($title), 'utf-8'));
	}


?>
