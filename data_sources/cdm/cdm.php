<?php

/**
 * Data source plugin for easyLOD that generates XML for an item in CONTENTdm.
 * Issues a query to CONTENTdm's web API to get all the metadata for the item
 * but only returns fields that are mapped to Dublin Core in the collection.
 */

/**
 * Required function. 
 *
 * Defines configuration settings for this plugin.
 *
 * You will need to change these two settings to use this plugin with your 
 * own CONTENTdm server.
 */
function dataSourceConfig() {
  return array(
    // URL of the CONTENTdm web services API.
    'ws_url' => 'http://contentdm.library.ca:81/dmwebservices/index.php?q=',
    // URL of the public interface to CONTENTdm; used in generating direct URLs
    // to item-level records.
    'contentdm_base_url' => 'http://contentdm.library.ca/cdm/ref/collection/',
    );
}

/**
 * Required function.
 *
 * Defines the XML namespace that the elements generated by this 
 * plugin belong to.
 */
function getDataSourceNamespaces() {
  return array('xmlns:dcterms' => 'http://purl.org/dc/terms/');
}

/**
 * Required function.
 *
 * Defines the 'human-readable' web page for an item. In this case, we
 * redirect users to the CONTENTdm native web interface for the item in
 * question.
 */
function getWebPage($identifier, $app) {
  $config = dataSourceConfig();
  list($namespace, $alias, $pointer) = explode(':', $identifier);
  $url = $config['contentdm_base_url'] . $alias . '/id/' . $pointer;
  $app->redirect($url, 303);
}

/**
 * Required function.
 *
 * Gets the item's info using the CONTENTdm web API
 * and convert it to Dublin Core XML.
 */ 
function getResourceData($identifier, $xml, $app) {
  $config = dataSourceConfig();
  list($namespace, $alias, $pointer) = explode(':', $identifier);

  // CONTENTdm nicknames for administrative fields. We don't want to return
  // these so we filter them out below.
  $admin_fields = array('fullrs', 'find', 'dmaccess', 'dmimage', 'dmcreated', 
    'dmmodified', 'dmoclcno', 'dmrecord'
  );

  // Get the collection's field configuration info, plus CONTENTdm's
  // Dublin Core configuration info. We will use these configurations
  // to filter the fields mapped to DC in the collection from the fields
  // that are not.
  $field_info = getCollectionFieldConfig('/' . $alias);
  $dc_field_info = getDcFieldInfo();

  // This is where we query the API, which returns a JSON representation of the metadata.
  $query_url = $config['ws_url'] . 'dmGetItemInfo' . '/' . $alias . '/' .  $pointer . '/json';
  $response = file_get_contents($query_url);
  $item_info = json_decode($response, TRUE);

  // Loop through the fields in the item's metadata and replace the fieldnames
  // with their Dublin Core mappings.
  if (is_array($item_info)) {
    foreach ($item_info as $field_key => $field_value) {
      // Fields with no values are returned as empty arrays.
      if (is_string($field_value) && !in_array($field_key, $admin_fields)) {
        // Replace nicknames with DC mappings from collection configuration.
        for ($i = 0; $i < count($field_info); $i++) {
          if (isset($field_key) && $field_key == $field_info[$i]['nick']) {
            $dc = $field_info[$i]['dc'];
            if (is_string($field_value) && $dc != 'BLANK') {
              $dc_label = $dc_field_info[$dc];
              // $dc_label is blank if it wasn't mapped in getDcFieldInfo(), so skip it.
              if (strlen($dc_label)) {
                $xml->writeElementNS('dc', strtolower($dc_label), NULL, $field_value);
              }
            }
          }
        } 
      } 
    }
    return $xml;
  }
  else {
    return FALSE;
  }
}

/**
 * Gets the collection's field configuration from CONTENTdm. The collection
 * is identified by $alias.
 */
function getCollectionFieldConfig($alias) {
  $config = dataSourceConfig();
  $query = $config['ws_url'] . 'dmGetCollectionFieldInfo' . $alias . '/json';
  $json = file_get_contents($query, false, NULL);
  return json_decode($json, true);
}

/**
 * Get the CONTENTdm Dublin Core field mappings.
 */
function getDcFieldInfo() {
  $config = dataSourceConfig();
  $request = $config['ws_url'] . 'dmGetDublinCoreFieldInfo/json';
  $json = file_get_contents($request, false, NULL);
  $raw_dc_field_info = json_decode($json, TRUE);

  // Convert from an anonymous array to a nick => name array.
  $dc_fields = array();
  foreach ($raw_dc_field_info as $field) {
    $dc_fields[$field['nick']] = $field['name'];
  }
  return $dc_fields;
}

