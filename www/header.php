<?php
require_once ('config.php');
require_once ('mysql.php');
require_once ('functions.php');

//	Random bench?
if ($_POST["random"]) {
	list ($benchID, $benchLat, $benchLong, $benchAddress, $benchInscription, $published) = get_random_bench();
	header('Cache-Control: no-store, must-revalidate');
	header('Expires: 0');
	header('Location: ' . "https://{$_SERVER['HTTP_HOST']}/bench/{$benchID}/",TRUE,302);
	die();
}

$page = strtolower($params[1]);
$benchInscription = "Welcome to OpenBenches";
$benchImage = "/android-chrome-512x512.png";

if ("bench" == $page) {
	$benchID = $params[2];

	if($benchID != null){
		list ($benchID, $benchLat, $benchLong, $benchAddress, $benchInscription, $published) = get_bench_details($benchID);
		$benchImage = get_image_url($benchID) . "/640";
	}

	//	Unpublished benches
	if($benchID != null && !$published) {
		//	Has it been merged?
		$mergedID = get_merged_bench($benchID);
		if (null == $mergedID) {
			//	Nope! Just deleted.  Include 404 content at the end of this page.
			header("HTTP/1.1 404 Not Found");
		} else {
			//	Yup! Where does it live now?
			header("Location: /bench/{$mergedID}",TRUE,301);
			die();
		}
	}
} 
if ("user" == $page) {
	//	Handled in user.php
}


?><!DOCTYPE html>
<html lang="en-GB">
<head prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb# place: http://ogp.me/ns/place#">
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<title>OpenBenches - by @edent &amp; @summerbeth</title>

	<link rel="apple-touch-icon"      sizes="180x180" href="/apple-touch-icon.png?cache=2017-08-08">
	<link rel="icon" type="image/png" sizes="32x32"   href="/favicon-32x32.png?cache=2017-08-08">
	<link rel="icon" type="image/png" sizes="16x16"   href="/favicon-16x16.png?cache=2017-08-08">
	<link rel="manifest"                              href="/manifest.json?cache=2017-08-08">
	<link rel="mask-icon"             color="#5bbad5" href="/safari-pinned-tab.svg?cache=2017-08-08">
	<link rel="shortcut icon"                         href="/favicon.ico?cache=2017-08-08">
	<meta name="theme-color" content="#ffffff">

	<!-- Twitter Specific Metadata https://dev.twitter.com/cards/markup -->
	<meta name="twitter:card"                            content="summary_large_image">
	<meta name="twitter:site"                            content="@openbenches">
	<meta name="twitter:creator"                         content="@openbenches" />
	<meta name="twitter:title"       property="og:title" content="OpenBenches">
	<meta                            property="og:url"   content="https://<?php echo "$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"; ?>">
	<meta name="twitter:image"       property="og:image" content="https://openbenches.org<?php echo $benchImage; ?>">
	<meta                            property="og:image:type"  content="image/jpeg">
	<meta                            property="og:image:width" content="640">
	<meta                            property="og:image:alt"   content="A photo of a bench with a memorial inscription on it.">

	<!-- Pinterest Specific https://developers.pinterest.com/docs/rich-pins/articles/? -->
	<meta                            property="og:type"         content="place">
	<meta name="twitter:description" property="og:description"  content="<?php echo htmlspecialchars($benchInscription); ?>">

	<!-- Facebook Specific Metadata https://developers.facebook.com/docs/sharing/opengraph/object-properties -->
	<meta                            property="place:location:latitude"  content="<?php echo $benchLat;  ?>">
	<meta                            property="place:location:longitude" content="<?php echo $benchLong; ?>">
	<meta                            property="og:rich_attachment"       content="true">
	<meta                            property="fb:app_id"                content="<?php echo FACEBOOK_APP_ID; ?>" />

	<!-- https://developers.google.com/search/docs/data-types/sitelinks-searchbox -->
	<script type="application/ld+json">
	{
		"@context": "https://schema.org",
		"@type":    "WebSite",
		"url":      "https://openbenches.org/",
		"potentialAction": {
			"@type":       "SearchAction",
			"target":      "https://openbenches.org/search/?search={search_term_string}",
			"query-input": "required name=search_term_string"
		}
	}
	</script>
	
	<link rel="alternate" type="application/rss+xml" href="https://openbenches.org/rss" />

	<link rel="stylesheet" href="/libs/normalize.8.0.0/normalize.min.css">

	<link rel="stylesheet" href="/style.css?cache=2018-10-28T08:30"/>

	<link rel="stylesheet" href="/libs/leaflet.1.3.4/leaflet.css" />
	<script src="/libs/leaflet.1.3.4/leaflet.js"></script>

	<script src="/libs/leaflet.markercluster.1.4.1/leaflet.markercluster.js"></script>
	<link rel="stylesheet" href="/libs/leaflet.markercluster.1.4.1/MarkerCluster.css">
	<link rel="stylesheet" href="/libs/leaflet.markercluster.1.4.1/MarkerCluster.Default.css">
</head>
<body>
	<hgroup itemscope itemtype="https://schema.org/WebPage">
		<h1>
			<a href="/">
				<img src="/images/openbencheslogo.svg"
				     id="header-image"
				     alt="[logo]: a bird flies above a bench">Open<wbr>Benches</a></h1>
<?php 
//	Unpublished benches
if("bench" == $page && $benchID != null && !$published) {
	include("404.php");
	die();
}
