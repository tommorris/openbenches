<?php
require_once ('config.php');
require_once ('functions.php');

//	Set up the database connection
$mysqli = new mysqli(DB_IP, DB_USER, DB_PASS, DB_TABLE);
if ($mysqli->connect_errno) {
	echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
}

if (!$mysqli->set_charset("utf8mb4")) {
	printf("Error loading character set utf8mb4: %s\n", $mysqli->error);
	exit();
}

function insert_bench($lat, $long, $inscription, $userID)
{
	//	Trim errant whitespace from the end before inserting
	$inscription = rtrim($inscription);

	$address = get_place_name($lat, $long);

	global $mysqli;
	$insert_bench = $mysqli->prepare(
		"INSERT INTO `benches`
				 (`benchID`,`latitude`,`longitude`,`address`, `inscription`,`description`,`present`,`published`, `added`,  `userID`)
		VALUES (NULL,		?,		  ?,			 ?,			 ?,			  '',			  '1'  ,	 '1', CURRENT_TIMESTAMP, ?		)");
	$insert_bench->bind_param('ddssi', $lat, $long, $address, $inscription, $userID);
	$insert_bench->execute();
	$resultID = $insert_bench->insert_id;
	if ($resultID) {
		$insert_bench->close();
		return $resultID;
	} else {
		$insert_bench->close();
		return $mysqli->error;
	}
}

function edit_bench($lat, $long, $inscription, $benchID, $published=true, $userID)
{
	$address = get_place_name($lat, $long);

	global $mysqli;
	$edit_bench = $mysqli->prepare(
		"UPDATE `benches`
			 SET `latitude` =	 ?,
				  `longitude` =	?,
				  `address` =	  ?,
				  `inscription` = ?,
				  `published` =	?,
				  `userID` =		?
		  WHERE `benches`.`benchID` = ?");
	$edit_bench->bind_param('ddssisi', $lat, $long, $address, $inscription, $published, $userID, $benchID);
	$edit_bench->execute();
	$edit_bench->close();

	return true;
}

function update_bench_address($benchID, $benchLat, $benchLong) {
	$address = get_place_name($benchLat, $benchLong);
	global $mysqli;
	$edit_bench = $mysqli->prepare(
		"UPDATE `benches`
			 SET `address` = ?
		  WHERE `benches`.`benchID` = ?");
	$edit_bench->bind_param('si', $address, $benchID);
	$edit_bench->execute();
	$edit_bench->close();

	return $address;
}


function insert_media($benchID, $userID, $sha1, $licence="CC BY-SA 4.0", $import=null, $media_type=null)
{
	global $mysqli;
	$insert_media = $mysqli->prepare(
		'INSERT INTO `media`
				 (`mediaID`,`benchID`,`userID`,`sha1`, `licence`, `importURL`, `media_type`)
		VALUES (NULL,      ?,        ?,       ?,      ?,         ?,          ?);'
	);

	$insert_media->bind_param('iissss', $benchID, $userID, $sha1, $licence, $import, $media_type);
	$insert_media->execute();
	$resultID = $insert_media->insert_id;
	if ($resultID) {
		$insert_media->close();
		return $resultID;
	} else {
		$insert_media->close();
		return $mysqli->error;
	}
}

function edit_media_type($mediaID, $media_type) {
	global $mysqli;
	$edit_media = $mysqli->prepare(
		"UPDATE `media`
			 SET `media_type` =	 ?
		  WHERE `media`.`media_type` = ?");
	$edit_media->bind_param('ss', $media_type, $mediaID);
	$edit_media->execute();
	$edit_media->close();

	return true;
}

function insert_user($provider, $providerID, $name)
{
	global $mysqli;

	//	Does the user already exist?
	//	Only applicable for Auth0 users
	if ("anon" != $provider) {
		$search_user = $mysqli->prepare("SELECT `userID` FROM `users`
			WHERE `provider` LIKE ? AND `providerID` LIKE ?");
		$search_user->bind_param('ss', $provider, $providerID);
		$search_user->execute();
		$search_user->bind_result($userID);
		# Loop through rows to build feature arrays
		while($search_user->fetch()) {
			if ($userID){
				$search_user->close();
				return $userID;
			}
		}
		$search_user->close();
	}

	$insert_user = $mysqli->prepare("INSERT INTO `users`
				 (`userID`, `provider`, `providerID`, `name`)
		VALUES (NULL,	  ?,			  ?,				?);"
	);

	$insert_user->bind_param('sss', $provider, $providerID, $name);
	$insert_user->execute();

	$resultID = $insert_user->insert_id;
	$insert_user->close();
	if ($resultID) {
		return $resultID;
	} else {
		return null;
	}
}

function get_nearest_benches($lat, $long, $distance=0.5, $limit=20, $truncated = false)
{
	global $mysqli;

	$get_benches = $mysqli->prepare(
		"SELECT
			(
				6371 * ACOS(COS(RADIANS(?)) *
				COS(RADIANS(latitude)) *
				COS(RADIANS(longitude) -
				RADIANS(?)) +
				SIN(RADIANS(?)) *
				SIN(RADIANS(latitude)))
			)
			AS distance, benchID, latitude, longitude, inscription, published
		FROM
			benches
		WHERE published = true
		HAVING distance < ?
		ORDER BY distance
		LIMIT 0 , ?");

	$get_benches->bind_param('ddddd', $lat, $long, $lat, $distance, $limit );
	$get_benches->execute();

	/* bind result variables */
	$get_benches->bind_result($dist, $benchID, $benchLat, $benchLong, $benchInscription, $published);

	# Build GeoJSON feature collection array
	$geojson = array(
		'type'		=> 'FeatureCollection',
		'features'  => array()
	);
	# Loop through rows to build feature arrays
	while($get_benches->fetch()) {

		# some inscriptions got stored with leading/trailing whitespace
		$benchInscription=trim($benchInscription);

		# if displaying on map need to truncate inscriptions longer than
		# 128 chars and add in <br> elements
		# N.B. this logic is also in get_all_benches()
		if ($truncated) {
			$benchInscriptionTruncate = mb_substr($benchInscription,0,128);
			if ($benchInscriptionTruncate !== $benchInscription) {
				$benchInscription = $benchInscriptionTruncate . '…';
			}
			$benchInscription=nl2br(htmlspecialchars($benchInscription));
		}

		$feature = array(
			'id' => $benchID,
			'type' => 'Feature',
			'geometry' => array(
				'type' => 'Point',
				# Pass Longitude and Latitude Columns here
				'coordinates' => array($benchLong, $benchLat)
			),
			# Pass other attribute columns here
			'properties' => array(
				'popupContent' => $benchInscription
			),
		);
		# Add feature arrays to feature collection array
		array_push($geojson['features'], $feature);
	}
	$get_benches->close();
	return $geojson;
}

function get_bench($benchID, $truncated, $media = false){
	return get_all_benches($benchID, false, $truncated, $media);
}

function get_all_benches($id = 0, $only_published = true, $truncated = false, $media = false)
{
	//	If media have been requested
	if($media) {
		$media_data = get_all_media($id);
	} else {
		$media_data = array();
	}

	global $mysqli;

	if(0==$id){
		$benchQuery = ">";
	} else {
		$benchQuery = "=";
	}

	if ($only_published){
		$where = "WHERE published = true AND benchID {$benchQuery} ?";
	} else {
		$where = "WHERE benchID {$benchQuery} ?";
	}

	$get_benches = $mysqli->prepare(
		"SELECT benchID, latitude, longitude, inscription, published FROM benches
		{$where}
		LIMIT 0 , 20000");

	$get_benches->bind_param('i', $id);
	$get_benches->execute();

	/* bind result variables */
	$get_benches->bind_result($benchID, $benchLat, $benchLong, $benchInscription, $published);

	# Build GeoJSON feature collection array
	$geojson = array(
		'type'		=> 'FeatureCollection',
		'features'  => array()
	);
	# Loop through rows to build feature arrays
	while($get_benches->fetch()) {

		# some inscriptions got stored with leading/trailing whitespace
		$benchInscription=trim($benchInscription);

		# if displaying on map need to truncate inscriptions longer than
		# 128 chars and add in <br> elements
		# N.B. this logic is also in get_nearest_benches()
		if ($truncated) {
			$benchInscriptionTruncate = mb_substr($benchInscription,0,128);
			if ($benchInscriptionTruncate !== $benchInscription) {
				$benchInscription = $benchInscriptionTruncate . '…';
			}
			$benchInscription=nl2br(htmlspecialchars($benchInscription));
		}

		if ($media) {
			$mediaFeature = $media_data[$benchID];
		} else {
			$mediaFeature = null;
		}
		$feature = array(
			'id' => $benchID,
			'type' => 'Feature',
			'geometry' => array(
				'type' => 'Point',
				# Pass Longitude and Latitude Columns here
				'coordinates' => array($benchLong, $benchLat)
			),
			# Pass other attribute columns here
			'properties' => array(
				'popupContent' => $benchInscription,
				'media' => $mediaFeature
			),
		);
		# Add feature arrays to feature collection array
		array_push($geojson['features'], $feature);
	}

	$get_benches->close();
	return $geojson;
}

function get_bench_details($benchID){
	global $mysqli;

	$get_bench = $mysqli->prepare(
		"SELECT benchID, latitude, longitude, address, inscription, published
		 FROM benches
		 WHERE benchID = ?"
	);

	$get_bench->bind_param('i', $benchID);
	$get_bench->execute();

	/* bind result variables */
	$get_bench->bind_result($benchID, $benchLat, $benchLong, $benchAddress, $benchInscription, $published);

	while($get_bench->fetch()) {
		$get_bench->close();
		return array ($benchID, $benchLat, $benchLong, htmlspecialchars($benchAddress), htmlspecialchars($benchInscription), $published);
	}
}

function get_random_bench(){
	global $mysqli;

	$get_bench = $mysqli->prepare(
		"SELECT benchID, latitude, longitude, address, inscription, published
		 FROM benches WHERE published = 1 ORDER BY RAND() LIMIT 1;"
	);

	$get_bench->execute();

	/* bind result variables */
	$get_bench->bind_result($benchID, $benchLat, $benchLong, $benchAddress, $benchInscription, $published);

	while($get_bench->fetch()) {
		$get_bench->close();
		return array ($benchID, $benchLat, $benchLong, $benchAddress, $benchInscription, $published);
	}
}

function get_image($benchID, $full = false)
{
	global $mysqli;

	$get_media = $mysqli->prepare(
		"SELECT sha1, userID, importURL, licence FROM media
		WHERE benchID = ?
		LIMIT 0 , 1");

	$get_media->bind_param('i',  $benchID );
	$get_media->execute();
	/* bind result variables */
	$get_media->bind_result($sha1, $userID, $importURL, $licence);

	$html = "";

	# Loop through rows to build feature arrays
	while($get_media->fetch()) {
		$get_media->close();
		// $userString = get_user($userID);
		if(null != $importURL) {
			$source = "<a href='{$importURL}'>Image Source</a>";
		}
		$licenceHTML = get_licence($licence);

		if ($full) {
			$imageLink = "/image/{$sha1}";
		} else {
			$imageLink = "/bench/{$benchID}";
		}

		$image_prefix = get_image_cache();

		$html .= "<a href='{$imageLink}'><img src='{$image_prefix}/image/{$sha1}' id='proxy-image' class='proxy-image' /></a><br>{$licenceHTML} {$source}";
		break;
	}

	return $html;
}

function get_user_from_media($benchID) {
	global $mysqli;

	$get_media = $mysqli->prepare(
		"SELECT users.userID, users.name
		FROM media
		INNER JOIN users ON media.userID = users.userID
		WHERE benchID = ?");

	$get_media->bind_param('i',  $benchID );
	$get_media->execute();
	/* bind result variables */
	$get_media->bind_result($userID, $userName);

	return array($userID, $userName);
}

function get_image_html($benchID, $full = true)
{
	//	Which bench? Should this link to the full image?
	//	If it's not linking to the full image, link to the details page.

	$media_types_array = get_media_types_array();

	global $mysqli;

	// $get_media = $mysqli->prepare(
	// 	"SELECT sha1, userID, importURL, licence, media_type
	// 	 FROM media
	// 	 WHERE benchID = ?");

	$get_media = $mysqli->prepare(
		"SELECT sha1, users.userID, users.name, users.provider, users.providerID, importURL, licence, media_type
		FROM media
		INNER JOIN users ON media.userID = users.userID
		WHERE benchID = ?");

	$get_media->bind_param('i',  $benchID );
	$get_media->execute();
	/* bind result variables */
	$get_media->bind_result($sha1, $userID, $userName, $userProvider, $userProviderID, $importURL, $licence, $media_type);

	$image_prefix = get_image_cache();

	$html = '';

	# Loop through rows to build the HTML
	while($get_media->fetch()) {

		$userHTML = "";
		//	Who uploaded this media
		if("anon" != $userProvider) {
			$userHTML = "<a href='/user/{$userID}'>℅ {$userName}</a>";
		}

		//	Was this imported from an external source?
		$source="";
		if(null != $importURL) {
			$source = "<a href='{$importURL}' rel='license'>{$licence}</a>";
		} else {
			$source = '<a rel="license" href="https://creativecommons.org/licenses/by-sa/4.0/"><img src="/images/cc-by-sa.svg" class="cc-icon" alt="Creative Commons Attribution Share-alike"/></a>';
		}

		//	When was the photo taken?
		$exif_html = get_exif_html(get_path_from_hash($sha1));

		//	Pannellum can't take full width images. This size should be quick to compute
		$panorama_image = "/image/{$sha1}/3396";

		//	Generate alt tag
		if (array_key_exists($media_type, $media_types_array)){
			$alt = $media_types_array[$media_type];
		} else {
			$alt = "Photograph of a bench";
		}

		//	Where to link the image to
		if($full) {
			$link = "/image/{$sha1}";
		} else {
			$link = "/bench/{$benchID}";
		}

		//	Start the container
		$html .= '<div itemprop="photo" class="benchImage">';

		//	What sort of image is it?
		if("360" == $media_type) {
			$panorama = "/libs/pannellum.2.4.1/pannellum.htm#panorama={$panorama_image}&amp;autoRotate=-2&amp;autoLoad=true";
			$html .= "<iframe width=\"600\" height=\"400\" allowfullscreen src=\"{$panorama}\"></iframe>";
		} else if("pano" == $media_type){
			$panorama = "/libs/pannellum.2.4.1/pannellum.htm#panorama={$panorama_image}&amp;autoRotate=-2&amp;autoLoad=true&amp;haov=360&amp;vaov=60";
			$html .= "<iframe width=\"600\" height=\"400\" allowfullscreen src=\"{$panorama}\"></iframe>";
		} else {

			$html .= "<a href='{$link}'>
							<img src='{$image_prefix}/image/{$sha1}' class='proxy-image' alt='{$alt}' />
						</a>";
		}

		$html .= "<h3 class='caption-heading'>
						<span class='caption'>{$source}&nbsp;{$exif_html}&nbsp;{$userHTML}</span>
					</h3>";

		$html .= "</div>";

		//	If this is the front page, link to the details page
		if(!$full){
			$html .= "<br><a href='{$link}'>View more about this bench</a>";
			//	Only one image is needed.
			break;
		}
	}

	$get_media->close();
	return $html;
}

function get_image_thumb($benchID, $size = IMAGE_THUMB_SIZE)
{
	global $mysqli;

	$get_media = $mysqli->prepare(
		"SELECT sha1 FROM media
		WHERE benchID = ?
		LIMIT 0 , 1");

	$get_media->bind_param('i',  $benchID );
	$get_media->execute();
	/* bind result variables */
	$get_media->bind_result($sha1);

	$thumb = "";

	# Loop through rows to build feature arrays
	while($get_media->fetch()) {
		$get_media->close();

		$image_prefix = get_image_cache($size);

		$thumb = "{$image_prefix}/image/{$sha1}";
		break;
	}

	return $thumb;
}

function get_all_media($benchID = 0)
{
	global $mysqli;

	if(0 == $benchID) {
		$get_media = $mysqli->prepare(
			"SELECT benches.benchID, media.sha1, media.importURL, media.licence, media.media_type, media.userID
			 FROM `benches`
				INNER JOIN
			`media` ON benches.benchID = media.benchID");
			$get_media->execute();
			/* bind result variables */
			$get_media->bind_result($benchID, $sha1, $importURL, $licence, $media_type, $userID);
	} else {
		$get_media = $mysqli->prepare(
			"SELECT benchID, sha1, importURL, licence, media_type, userID
			FROM media
			WHERE benchID = ?");
			$get_media->bind_param('i',  $benchID );
			$get_media->execute();
			/* bind result variables */
			$get_media->bind_result($benchID, $sha1, $importURL, $licence, $media_type, $user);
	}

	$media = array();

	# Loop through rows to build the array
	while($get_media->fetch()) {
		$media_data = array();

		$media_data["URL"] = "/image/{$sha1}";

		if(null != $importURL) {
			$media_data["importURL"] = $importURL;
		}

		$media_data["licence"]    = $licence;
		$media_data["media_type"] = $media_type;
		$media_data["sha1"]       = $sha1;
		$media_data["user"]       = $user;

		try {
			$imagick = new \Imagick(realpath(get_path_from_hash($sha1)));
		} catch (Exception $e) {
			$refer = $_SERVER["HTTP_REFERER"];
			error_log("Image error! {$imagePath} - from {$refer} - {$e}" , 0);
			die();
		}
		$height = $imagick->getImageHeight();
		$width  = $imagick->getImageWidth();
		$imagick->clear();

		$media_data["width"]      = $width;
		$media_data["height"]     = $height;

		$media[$benchID][] = $media_data;
	}

	$get_media->close();
	return $media;
}

function get_rss($items = 10) {
	global $mysqli;

	$get_rss = $mysqli->prepare(
		"SELECT benches.benchID, benches.inscription, benches.address, benches.added, media.sha1

		FROM `benches`
			INNER JOIN
		`media` ON benches.benchID = media.benchID
		WHERE benches.published = true
		ORDER by benches.benchID DESC
		LIMIT ?");

	$get_rss->bind_param('i', $items);
	$get_rss->execute();
	$get_rss->bind_result($benchID, $benchInscription, $benchAddress, $benchAdded, $sha1);

	$rssItems = array();

	while($get_rss->fetch()) {
		$rssData = array();

		$rssData["benchID"] = $benchID;
		$rssData["benchInscription"] = htmlspecialchars($benchInscription);
		$rssData["benchAddress"] = $benchAddress;
		$rssData["benchAdded"] = $benchAdded;
		$rssData["sha1"][] = $sha1;

		if (key_exists($benchID,$rssItems)){
			$rssItems[$benchID]["sha1"][] = $sha1;
		} else {
			$rssItems[$benchID] = $rssData;
		}
	}

	$get_rss->close();
	return $rssItems;
}

function get_image_url($benchID)
{
	global $mysqli;

	$get_media = $mysqli->prepare(
		"SELECT sha1 FROM media
		WHERE benchID = ?
		LIMIT 0 , 1");

	$get_media->bind_param('i',  $benchID );
	$get_media->execute();
	/* bind result variables */
	$get_media->bind_result($sha1);

	$url = "";

	# Loop through rows to build feature arrays
	while($get_media->fetch()) {
		$url = "/image/{$sha1}";
		$get_media->close();
		break;
	}

	return $url;
}

function get_user_from_bench($benchID) {
	global $mysqli;
	$get_user_from_bench = $mysqli->prepare(
		"SELECT userID FROM benches
		WHERE benchID = ?
		LIMIT 0 , 1");

	$get_user_from_bench->bind_param('i',  $benchID);
	$get_user_from_bench->execute();
	/* bind result variables */
	$get_user_from_bench->bind_result($userID);

	# Loop through rows to build feature arrays
	while($get_user_from_bench->fetch()) {
		$get_user_from_bench->close();
		return get_user($userID)["name"];
		die();
	}
}

function get_user($userID)
{
	global $mysqli;
	$get_user = $mysqli->prepare(
		"SELECT provider, providerID, name FROM users
		WHERE userID = ?
		LIMIT 0 , 1");


	$get_user->bind_param('i',  $userID);
	$get_user->execute();
	/* bind result variables */
	$get_user->bind_result($provider, $providerID, $name);

	$userString = "";
	$user = array();
	# Loop through rows to build feature arrays
	while($get_user->fetch()) {
		if ("anon" != $provider){
			$name = htmlspecialchars($name);
			$user["provider"] = $provider;
			$user["providerID"] = $providerID;
			$user["name"] = $name;
		} else {
			$user["provider"] = "anonymous";
			$user["providerID"] = null;
			$user["name"] = null;
		}
	}
	$get_user->close();
	return $user;
}

function get_user_id($provider, $username, $is_id = false) {
	global $mysqli;
	if ($is_id) {
		$get_user_id = $mysqli->prepare(
			"SELECT userID FROM users
			WHERE provider = ?
			AND	providerID = ?
			LIMIT 0 , 1");
	} else {
		$get_user_id = $mysqli->prepare(
			"SELECT userID FROM users
			WHERE provider = ?
			AND	name = ?
			LIMIT 0 , 1");
	}

	$get_user_id->bind_param('ss', $provider, $username);
	$get_user_id->execute();
	/* bind result variables */
	$get_user_id->bind_result($userID);

	# Loop through rows to build feature arrays
	while($get_user_id->fetch()) {
		$get_user_id->close();
		return $userID;
		die();
	}
}


function get_licence($licenceID)
{
	global $mysqli;

	$get_licence = $mysqli->prepare(
		"SELECT longName, url FROM licences
		WHERE shortName = ?
		LIMIT 0 , 1");

	$get_licence->bind_param('s',  $licenceID );
	$get_licence->execute();
	/* bind result variables */
	$get_licence->bind_result($longName, $url);

	$html = "";

	# Loop through rows to build feature arrays
	while($get_licence->fetch()) {
		$get_licence->close();
		$longName = htmlspecialchars($longName);
		$licenceID = htmlspecialchars($licenceID);
		$html .= "<small><a href='{$url}' title='{$longName}'>{$licenceID}</a></small>";
		break;
	}

	return $html;
}

function get_admin_list()
{
	global $mysqli;

	$get_list = $mysqli->prepare("SELECT `benchID`, `inscription` FROM `benches` ORDER BY `benchID` DESC LIMIT 0 , 4096");

	$get_list->execute();
	/* bind result variables */
	$get_list->bind_result($benchID, $inscription);

	$html = "<ul>";

	# Loop through rows to build feature arrays
	while($get_list->fetch()) {
		//get_edit_key
		$bench	= $benchID;
		$key	  = urlencode(get_edit_key($bench));
		$inscrib = nl2br(htmlspecialchars($inscription, ENT_HTML5, "UTF-8", false));
		$html	.= "<li>{$bench} <a href='/edit/{$bench}/{$key}'>{$inscrib}</a></li>";
	}

	$get_list->close();
	return $html .= "</ul>";
}

function get_media_types_html($name = "") {
	global $mysqli;

	$get_media = $mysqli->prepare("SELECT `shortName`, `longName` FROM `media_types` ORDER BY `displayOrder` ASC");

	$get_media->execute();
	/* bind result variables */
	$get_media->bind_result($shortName, $longName);

	$html = "<select name='media_type{$name}' id='media_type{$name}'>";

	$count = 1;

	# Loop through rows to build feature arrays
	while($get_media->fetch()) {
		if ($count == $name) {
			$html .= "<option value='{$shortName}' selected>{$longName}</option>";
		} else {
			$html .= "<option value='{$shortName}'>{$longName}</option>";
		}
		$count++;
	}

	$get_media->close();
	return $html .= "</select>";
}

function get_media_types_array() {
	global $mysqli;

	$get_media_types = $mysqli->prepare("SELECT `shortName`, `longName` FROM `media_types` ORDER BY `displayOrder` ASC");

	$get_media_types->execute();
	/* bind result variables */
	$get_media_types->bind_result($shortName, $longName);

	$media_types_array = array();

	# Loop through rows to build feature arrays
	while($get_media_types->fetch()) {
		$media_types_array[$shortName] = $longName;
	}

	$get_media_types->close();
	return $media_types_array;
}

function get_search_results($q) {
	global $mysqli;

	//	Replace spaces in query with `[[:space:]]*`
	$q = str_replace(" ","[[:space:]]*", $q);
	$quoteTranslation=array("\"" => "[\"“”]", "“" => "[\"“]", "”" => "[\"”]", "'" => "['‘’]", "‘" => "['‘]", "’" => "['’]");
	$q = strtr($q,$quoteTranslation);

	//	Query will be like
	//	SELECT * FROM `benches` WHERE `inscription` REGEXP 'of[[:space:]]*Paul[[:space:]]*[[:space:]]*Willmott'
	$search = $mysqli->prepare(
		"SELECT `benchID`, `inscription`, `address`
		 FROM	`benches`
		 WHERE  `inscription`
		 REGEXP	?
		 AND	 `published` = 1
		 LIMIT 0 , 20");

	$search->bind_param('s',$q);

	$search->execute();
	/* bind result variables */
	$search->bind_result($benchID, $inscription, $address);

	$results = array();
	# Loop through rows to build feature arrays
	while($search->fetch()) {
		if($address != null){
			$inscription = htmlspecialchars($inscription) ."<br />Location: ". htmlspecialchars($address);
		}
		$results[$benchID] = $inscription;
	}

	$search->close();
	return $results;
}

function get_bench_count() {
	global $mysqli;

	$result = $mysqli->query("SELECT COUNT(*) FROM `benches` WHERE published = true");
	$row = $result->fetch_row();
	$result->close();
	return $row[0];
}

function get_leadboard_benches_html() {
	global $mysqli;

	$get_leaderboard = $mysqli->prepare("
		SELECT users.userID, users.name, users.provider, users.providerID, COUNT(*) AS USERCOUNT
		FROM `benches`
		INNER JOIN users ON benches.userID = users.userID
		WHERE benches.published = TRUE
		GROUP by users.userID
		ORDER by USERCOUNT DESC");

	$get_leaderboard->execute();
	$get_leaderboard->bind_result($userID, $username, $provider, $providerID, $count);

	$html = "<ul class='leaderboard-list'>";
	while($get_leaderboard->fetch()) {
		if("twitter"==$provider){
			$html .= "<li><a href='/user/{$userID}/'><img src='https://avatars.io/{$provider}/{$username}/small' class='avatar'>$username</a> {$count}</li>";
		} else if("github"==$provider){
			$html .= "<li><a href='/user/{$userID}/'><img src='https://avatars0.githubusercontent.com/u/{$providerID}?v=4&amp;s=48' class='avatar'>$username</a> {$count}</li>";
		} else if("facebook"==$provider){
			$html .= "<li><a href='/user/{$userID}/'><img src='https://avatars.io/{$provider}/{$providerID}/small' class='avatar'>$username</a> {$count}</li>";
		}
	}
	$get_leaderboard->close();
	return $html .= "</ul>";
}

function get_leadboard_media_html() {
	global $mysqli;

	$get_leaderboard = $mysqli->prepare("
		SELECT users.userID, users.name, users.provider, COUNT(*) AS USERCOUNT
		FROM `media`
		INNER JOIN users ON media.userID = users.userID
		WHERE users.provider != 'anon'
		GROUP by users.userID
		ORDER by USERCOUNT DESC");

	$get_leaderboard->execute();
	$get_leaderboard->bind_result($userID, $username, $provider, $count);

	$html = "<ul class='leaderboard-list'>";
	while($get_leaderboard->fetch()) {
		if("twitter"==$provider){
			$html .= "<li><a href='/user/{$userID}/'><img src='https://avatars.io/{$provider}/{$username}/small' class='avatar'>$username</a> {$count}</li>";
		}
	}
	$get_leaderboard->close();
	return $html .= "</ul>";
}

function get_user_bench_list($userID)
{
	global $mysqli;

	$get_user_list = $mysqli->prepare(
		"SELECT `benchID`, `inscription`, `address`
		 FROM	  `benches`
		 WHERE  `userID` = ?
		 AND	  `published` = 1
		 LIMIT 0 , 1024");
	$get_user_list->bind_param('i', $userID);

	$get_user_list->execute();
	$get_user_list->bind_result($benchID, $inscription, $address);

	$results = array();
	while($get_user_list->fetch()) {
		if($address != null){
			$inscription = htmlspecialchars($inscription) ."<br />Location: ". htmlspecialchars($address);
		}
		$results[$benchID] = $inscription;
	}

	$get_user_list->close();
	return $results;
}

function get_user_map($userID)
{
	global $mysqli;

	// $where = "WHERE published = true AND userID = {$userID}";

	$get_benches = $mysqli->prepare(
		"SELECT benchID, latitude, Longitude, inscription, published FROM benches
		WHERE published = true AND userID = ?
		LIMIT 0 , 20000");

	$get_benches->bind_param('i', $userID);
	$get_benches->execute();

	/* bind result variables */
	$get_benches->bind_result($benchID, $benchLat, $benchLong, $benchInscription, $published);

	# Build GeoJSON feature collection array
	$geojson = array(
		'type'		=> 'FeatureCollection',
		'features'  => array()
	);
	# Loop through rows to build feature arrays
	while($get_benches->fetch()) {

		# some inscriptions got stored with leading/trailing whitespace
		$benchInscription=trim($benchInscription);

		# if displaying on map need to truncate inscriptions longer than
		# 128 chars and add in <br> elements
		# N.B. this logic is also in get_nearest_benches()
		$benchInscriptionTruncate = mb_substr($benchInscription,0,128);
		if ($benchInscriptionTruncate !== $benchInscription) {
			$benchInscription = $benchInscriptionTruncate . '…';
		}
		$benchInscription=nl2br(htmlspecialchars($benchInscription));

		$feature = array(
			'id' => $benchID,
			'type' => 'Feature',
			'geometry' => array(
				'type' => 'Point',
				# Pass Longitude and Latitude Columns here
				'coordinates' => array($benchLong, $benchLat)
			),
			# Pass other attribute columns here
			'properties' => array(
				'popupContent' => $benchInscription
			),
		);
		# Add feature arrays to feature collection array
		array_push($geojson['features'], $feature);
	}

	$get_benches->close();
	return $geojson;
}

function merge_benches($originalID, $duplicateID) {
	global $mysqli;

	//	Unpublish duplicate bench
	$merge_two_benches = $mysqli->prepare(
		"UPDATE `benches` SET `published` = '0' WHERE `benches`.`benchID` = ?;");
	$merge_two_benches->bind_param('i', $duplicateID);
	$merge_two_benches->execute();
	$merge_two_benches->close();

	//	Redirect duplicate bench
	$merge_two_benches = $mysqli->prepare(
		"INSERT INTO `merged_benches` (`benchID`, `mergedID`) VALUES (?, ?);");
	$merge_two_benches->bind_param('ii', $duplicateID, $originalID);
	$merge_two_benches->execute();
	$merge_two_benches->close();

	//	Merge photos
	$merge_two_benches = $mysqli->prepare(
		"UPDATE `media` SET `benchID` = ? WHERE `media`.`benchID` = ?;");
	$merge_two_benches->bind_param('ii', $originalID, $duplicateID);
	$merge_two_benches->execute();
	$merge_two_benches->close();

	return true;
}

function get_merged_bench($benchID) {
	global $mysqli;
	$get_merge_from_bench = $mysqli->prepare(
		"SELECT mergedID FROM merged_benches
		WHERE benchID = ?
		LIMIT 0 , 1");

	$get_merge_from_bench->bind_param('i',  $benchID);
	$get_merge_from_bench->execute();
	/* bind result variables */
	$get_merge_from_bench->bind_result($mergedID);

	# Loop through rows to build feature arrays
	while($get_merge_from_bench->fetch()) {
		$get_merge_from_bench->close();
		return $mergedID;
		die();
	}
}
