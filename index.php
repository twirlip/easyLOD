<?php

/**
 * easyLOD, an application that exposes content as Linked Open Data. Uses plugins 
 * to get data from different sources. Written in the Slim micro-framework,
 * slimframework.com.
 * 
 * Distributed under the MIT License, http://opensource.org/licenses/MIT.
 */

// Slim setup.
require 'lib/Slim/Slim.php';
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim(
    // 'mode' => 'production' // Comment out in production.
);

/**
 * Route for /resource. Redirect browsers that supply a request header of
 * Accept: application/rdf+xml to this URI get back an RDF representation
 * of the metadata for the item identified in the request. Redirects other
 * browsers (e.g., browsers used by humans) to whatever is defined by the
 * source plugin's getWebPage() function.
 */
$app->get('/resource/:identifier', function ($identifier) use ($app) {
  $identifier_namespace = getIdentifierNamespace($identifier);
  $request = $app->request();
  // If the request is from a Linked Data browser, redirect it to the 'data' URL.
  if ($request->headers('Accept') == 'application/rdf+xml') {
    $data_path = swapPaths($request->getPath(), 'data');
    $url = $request->getUrl() . $data_path;
    $app->redirect($url, 303);
  }
  // If the request is not from a Linked Data browser, redirect it to a human-readable
  // page for the item.
  else {
    require 'data_sources/' . $identifier_namespace . '/' . $identifier_namespace . '.php';
    getWebPage($identifier, $app);
  }
});

/**
 * Route for /data. Returns the RDF representation of the item, after 
 * adding metadata generated by the appropriate data source plugin.
 */
$app->get('/data/:identifier', function ($identifier) use ($app) {
  // Get the identifier namespace so we can use the corresponding data 
  // source plugin.
  $identifier_namespace = getIdentifierNamespace($identifier);
  require 'data_sources/' . $identifier_namespace . '/' . $identifier_namespace . '.php';

  $request = $app->request();
  $app->response()->header('Content-Type', 'text/xml');
  $xml = new XMLWriter();
  $xml->openMemory();
  $xml->setIndent(TRUE);
  $xml->startDocument('1.0', 'utf-8', NULL);
  $xml->startElementNS('rdf', 'RDF', NULL);

  // Add XML namespaces, including any supplied by the data source plugin,
  // to the <RDF> element.
  $rdf_namespace = array('xmlns:rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
  $data_source_namespaces = getDataSourceNamespaces();
  $namespaces = array_merge($rdf_namespace, $data_source_namespaces);
  foreach ($namespaces as $prefix => $uri) {
    $xml->writeAttribute($prefix, $uri);
  }

  $xml->startElementNS('rdf', 'Description', NULL);
  $resource_path = swapPaths($request->getPath(), 'resource');
  $xml->writeAttributeNS('rdf', 'about', NULL, $request->getUrl() . $resource_path);

  // Add the XML generated from the source plugin.
  $xml = getResourceData($identifier, $xml, $app);

  $xml->endElement(); // <Description>
  $xml->endElement(); // <RDF>
  echo $xml->outputMemory();
});

$app->run();

/**
 * Functions.
 */

/**
 * Pick out the identifier 'namespace'.
 */
function getIdentifierNamespace($identifier) {
  $identifier_parts = explode(':', $identifier);
  return $identifier_parts[0];
}

/**
 * If the path in $original is the 'resource' path, convert it
 * to the 'data' path, and vice versa.
 */
function swapPaths($original, $new) {
  if ($new == 'data') {
    return preg_replace('/\/resource\//', '/data/', $original);
  }
  if ($new = 'resource') {
    return preg_replace('/\/data\//', '/resource/', $original);
  }
}
