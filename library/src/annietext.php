<?php
/* annietext.php
 * Copyright (c) 2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Annie Text manipulation service.
 */

namespace Annie\Advisor;

class Text {

  //
  // VARIABLES
  //

  private $defaultreplaceables;

  //
  // CONSTRUCTORS & DESTRUCTORS
  //

  public function __construct($settings) {

    $clientname = $settings['my']['name'];
    if (empty($clientname)) {
      $clientname = "annie"; // default value might be wise to hit an existing mail address
    }

    // keyword list to replace from text template
    // nb! presetting here is not mandatory, this is for reference mostly
    $this->defaultreplaceables = (object)array(
      "hostname" => $clientname,
      // very often used, for example
      //"survey.title" => ""
    );
  }

  public function __destruct() {
    // clean up & close
    $this->defaultreplaceables = null;
  }

  //
  // PRIVATES
  //

  public function getDefaultreplaceables() {
    return $this->defaultreplaceables;
  }

  //
  // FUNCTIONS
  //

  /*
  fillPlaceholders
  - given text template may contain placeholders or variables like "{{firstname}}"
    (surrounding spaces from placeholders are removed, e.g. they are optional)
  - given replaceables (object array or "map" or "hash") are then used to replace those placeholders
  - resulting processed text is returned
  - optional: prefix is for placeholders like "{{ contact.firstname }}", works but not currently used
  */
  public function fillPlaceholders($texttemplate,$replaceables,$prefix = null) {

    // if using prefix add prefix to (data) replaceables
    $prefixreplaceables = (object)array();
    if (!empty($prefix)) {
      foreach ($replaceables as $replacekey => $replacevalue) {
        $prefixreplaceables->{$prefix.".".$replacekey} = $replacevalue;
      }
    } else {
      $prefixreplaceables = $replaceables;
    }

    // merge defaults with given. latter one wins.
    // use type cast to array as object is not supported by array_merge.
    $myreplaceables = (object)array_merge((array)$this->defaultreplaceables, (array)$prefixreplaceables);
    $myreplaceables = (object)array_merge((array)$myreplaceables, (array)$replaceables); //repeat for non prefixed

    $processedtext = $texttemplate;

    // replace string placeholders, like "{{ contact.firstname }}"
    $placeholders = preg_split('/[^{]*(\{\{[^}]+\}\})[^{]*/', $processedtext, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
    if (gettype($placeholders) === "array" && $placeholders[0] !== $processedtext) {
      foreach ($placeholders as $placeholder) {
        $replacekey = trim(strtolower(preg_replace('/\{\{\s*([^}]+)\s*\}\}/', '$1', $placeholder)));
        if (array_key_exists($replacekey, $myreplaceables)) {
          $processedtext = str_replace($placeholder, $myreplaceables->$replacekey, $processedtext);
        }
      }
    }

    return $processedtext;
  }

}//class
