<?php

// Includes the core classes
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
if (!class_exists('WP_Http')) {
  require_once(ABSPATH . WPINC . '/class-http.php');
}

class ApimoProrealestateSynchronizer
{
  /**
   * Instance of this class
   *
   * @var ApimoProrealestateSynchronizer
   */
  private static $instance;

  /**
   * @var string
   */
  private $siteLanguage;

  /**
   * Constructor
   *
   * Initializes the plugin so that the synchronization begins automatically every hour,
   * when a visitor comes to the website
   */
  public function __construct()
  {
    // Retrieve site language
    $this->siteLanguage = $this->getSiteLanguage();

    // Trigger the synchronizer event every hour only if the API settings have been configured
    if (is_array(get_option('apimo_prorealestate_synchronizer_settings_options'))) {
      if (isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_provider']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_token']) &&
        isset(get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency'])
      ) {
        add_action(
          'apimo_prorealestate_synchronizer_hourly_event',
          array($this, 'synchronize')
        );

    // For debug only, you can uncomment this line to trigger the event every time the blog is loaded
        //add_action('init', array($this, 'synchronize'));
      }
    }
  }

  /**
   * Retrieve site language
   */
  private function getSiteLanguage()
  {
    return substr(get_bloginfo('language'), 0, 2);
  }

  /**
   * Creates an instance of this class
   *
   * @access public
   * @return ApimoProrealestateSynchronizer An instance of this class
   */
  public static function getInstance()
  {
    if (null === self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  /**
   * Synchronizes Apimo and Pro Real Estate plugnins estates
   *
   * @access public
   */
  public function synchronize()
  {

    // Gets the properties
    $return = $this->callApimoAPI(
      'https://api.apimo.pro/agencies/'
      . get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_agency']
      . '/properties',
      'GET'
    );
    // Parse internal mappings

    if (file_exists(dirname(__FILE__) . '/mappings.php')) {
      $mappings = include('mappings.php');
    } else {
      $mappings = [];
    }

    // To init your wordpress with mappings
    // $this->initTaxonomies($mappings);

    // Parses the JSON into an array of properties object
    $jsonBody = json_decode($return['body']);

    // debug for a single property
    //if (is_object($jsonBody)) {
    //   $properties = [$jsonBody];

    if (is_object($jsonBody) && isset($jsonBody->properties)) {
      $properties = $jsonBody->properties;

      if (is_array($properties)) {
        foreach ($properties as $property) {

          // Parse the property object
          $data = $this->parseJSONOutput($property, $mappings);

          if (null !== $data) {
            // Creates or updates a listing
            $this->manageListingPost($data);
          }
        }
        // $this->deleteOldListingPost($properties, $mappings);
      }
    }
  }

  /**
   * Map apimo data to internal wordpress data
   *
   * @access private
   * @param stdClass $data
   * @param stdClass $mappings
   * @return array $data
   */
  private function mapIdToValue($mappings, $type, $id)
  {
    if ($mappings[$type]){
      if(is_array($mappings[$type][$id])){
        return $mappings[$type][$id][0];
      }
      return $mappings[$type][$id];
    }

    return $id;
  }

  private function initTaxonomies($mappings)
  {

    foreach($mappings['type_mappings'] as  $key => $value){
      wp_insert_term($value, 'property_type' );
    };

    foreach($mappings['subtype_mappings'] as  $key => $value){
      $type = $mappings['type_mappings'][$value[1]];
      $term = term_exists( $type, "property_type" );

      wp_insert_term($value[0], 'property_type', array("parent" => $term['term_id']));
    }
    var_dump("Init has been done");
    die;
  }

  /**
   * Parses a JSON body and extracts selected values
   *
   * @access private
   * @param stdClass $property
   * @return array $data
   */
  private function parseJSONOutput($property, $mappings)
  {
    $data = array(
      'user' => $property->user,
      'firstName' => $property->user->firstname,
      'lastName' => $property->user->lastname,
      'email' => $property->user->email,
      'updated_at' => $property->updated_at,
      'postTitle' => array(),
      'postContent' => array(),
      'images' => array(),
      'customMetaAltTitle' => $property->address,
      'customMetaPrice' => (!$property->price->value ? __('Price on ask') : $property->price->value),
      'customMetaPricePrefix' => '',
      'customMetaPricePostfix' => '',
      'customMetaSqFt' => preg_replace('#,#', '.', $property->area->value),
      'customMetaVideoURL' => '',
      'customMetaMLS' => $property->id,
      'customMetaLatLng' => (
      $property->latitude && $property->longitude
        ? $property->latitude . ', ' . $property->longitude
        : ''
      ),
      'customMetaExpireListing' => '',
      'ct_property_type' => $this->mapIdToValue($mappings, 'type_mappings', $property->type),
      'ct_property_subtype' => $this->mapIdToValue($mappings,'subtype_mappings',  $property->subtype),
      'rooms' => 0,
      'beds' => 0,
      'customTaxBeds' => 0,
      'customTaxBaths' => 0,
      'ct_status' => 'en-vente',
      'customTaxCity' => $property->city->name,
      'customTaxState' => '',
      'customTaxZip' => $property->city->zipcode,
      'customTaxCountry' => $property->country,
      'customTaxCommunity' => '',
      'customTaxFeat' => ''
    );

    foreach ($property->comments as $comment) {
      $data['postTitle'][$comment->language] = $comment->title;
      $data['postContent'][$comment->language] = $comment->comment;
    }

    $data['rooms'] = $property->rooms;
    $data['beds'] = $property->bedrooms;

    foreach ($property->areas as $area) {
      if ($area->type == 1 ||
        $area->type == 53 ||
        $area->type == 70
      ) {
        $data['customTaxBeds'] += $area->number;
      } else if ($area->type == 8 ||
        $area->type == 41 ||
        $area->type == 13 ||
        $area->type == 42
      ) {
        $data['customTaxBaths'] += $area->number;
      }
    }

    foreach ($property->pictures as $picture) {
      $data['images'][] = array(
        'id' => $picture->id,
        'url' => $picture->url,
        'rank' => $picture->rank
      );
    }
    foreach ($property->regulations as $regulation) {
      if($regulation->type == 1){
        $data['customGlobalEnergyPerformanceIndex'] = $regulation->value;
      }
      if($regulation->type == 2){
        $data['customRenewableEnergyPerformanceIndex'] = $regulation->value;
      }
    }
    return $data;
  }

  /**
   * Creates or updates a listing post
   *
   * @param array $data
   */
  private function manageListingPost($data)
  {
    // Converts the data for later use

    $postTitle = $data['postTitle'][$this->siteLanguage];
    if ($postTitle == '') {
      foreach ($data['postTitle'] as $lang => $title) {
        $postTitle = $title;
      }
    }


    $postContent = $data['postContent'][$this->siteLanguage];
    if ($postContent == '') {
      foreach ($data['postContent'] as $lang => $title) {
        $postContent = $title;
      }
    }
    $email = $data['email'];
    $firstName = $data['firstName'];
    $lastName = $data['lastName'];
    $postUpdatedAt = $data['updated_at'];
    // $images = array_slice($data['images'], 0, 2);
    $images = $data['images'];
    $customMetaAltTitle = $data['customMetaAltTitle'];
    $ctPrice = str_replace(array('.', ','), '', $data['customMetaPrice']);
    $customMetaPricePrefix = $data['customMetaPricePrefix'];
    $customMetaPricePostfix = $data['customMetaPricePostfix'];
    $customMetaSqFt = $data['customMetaSqFt'];
    $customMetaVideoURL = $data['customMetaVideoURL'];
    $customMetaMLS = $data['customMetaMLS'];
    $customMetaLatLng = $data['customMetaLatLng'];
    $customMetaExpireListing = $data['customMetaExpireListing'];
    $ctPropertyType = $data['ct_property_type'];
    $ctPropertySubtype = $data['ct_property_subtype'];
    $customGlobalEnergyPerformanceIndex = $data['customGlobalEnergyPerformanceIndex'];
    $customRenewableEnergyPerformanceIndex = $data['customRenewableEnergyPerformanceIndex'];
    $rooms = $data['rooms'];
    $beds = $data['beds'];
    $customTaxBeds = $data['customTaxBeds'];
    $customTaxBaths = $data['customTaxBaths'];
    $customStatus = $data['ct_status'];
    $customTaxCity = $data['customTaxCity'];
    $customTaxState = $data['customTaxState'];
    $customTaxZip = $data['customTaxZip'];
    $customTaxCountry = $data['customTaxCountry'];
    $customTaxCommunity = $data['customTaxCommunity'];
    $customTaxFeat = $data['customTaxFeat'];

    // Creates a listing post
    $postInformation = array(
      'post_title' => wp_strip_all_tags(trim($postTitle)),
      'post_content' => $postContent,
      'post_type' => 'listings',
      'post_status' => 'publish',
      'post_author' => $postAuthor
    );

    // Add email based on author
    $user = get_user_by('email', $email);
    if($user){
      $postInformation['post_author'] = $user->ID;
    }else{
      $postInformation['post_author'] = 1;
    }

    // Verifies if the listing does not already exist
    if ($postTitle != '') {
      $post = get_page_by_title($postTitle, OBJECT, 'listings');

      if (NULL === $post) {
        // Insert post and retrieve postId
        $postId = wp_insert_post($postInformation);

        // set some terms once for all
        wp_set_post_terms($postId, $customStatus, 'ct_status', FALSE);

      }
      else {
        // Verifies if the property is not to old to be added
        if (strtotime($postUpdatedAt) <= strtotime('-5 days')) {
          return;
        }

        $postInformation['ID'] = $post->ID;
        $postId = $post->ID;
        $postmetas = get_post_meta($post->ID);

        // Update post
        wp_update_post($postInformation);
      }

      // Delete attachments that has been removed
      $attachments = get_attached_media('image', $postId);
      foreach ($attachments as $attachment) {
        $imageStillPresent = false;
        foreach ($images as $image) {
          if ($attachment->post_content == $image['id'] &&
            $this->getFileNameFromURL($attachment->guid) == $this->getFileNameFromURL($image['url'])) {
            $imageStillPresent = true;
          }
        }
        if (!$imageStillPresent) {
          wp_delete_attachment($attachment->ID, TRUE);
        }
      }

      // Updates the image and the featured image with the first given image
      $imagesIds = array();
      $has_thumnail = false;

      foreach ($images as $image) {
        // Tries to retrieve an existing media
        $media = $this->isMediaPosted($image['id']);

        // If the media does not exist, upload it
        if (!$media) {
          media_sideload_image($image['url'], $postId);

          // Retrieve the last inserted media
          $args = array(
            'post_type' => 'attachment',
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
          );
          $medias = get_posts($args);

          // Just one media, but still an array returned by get_posts
          foreach ($medias as $attachment) {
            // Make sure the media's name is equal to the file name
            wp_update_post(array(
              'ID' => $attachment->ID,
              'post_name' => $postTitle,
              'post_title' => $postTitle,
              'post_content' => 'APIMO '.$image['id'],
            ));
            $media = $attachment;
          }
        }

        if (!empty($media) && !is_wp_error($media)) {
          $imagesIds[$image['rank']] = $media->ID;
        }

        // Set the first image as the thumbnail
        // apimo does not begins at 1
        // $positions = implode(',', $imagesIds);
        // $thumbnail_rank  = min($positions);

        if (!$has_thumnail) {
          set_post_thumbnail($postId, $media->ID);
          $has_thumnail = true;
        }
      }

      update_post_meta($postId, '_ct_images_position', $positions);

      // Updates custom meta
      update_post_meta($postId, '_ct_listing_alt_title', esc_attr(strip_tags($customMetaAltTitle)));
      update_post_meta($postId, '_ct_price', esc_attr(strip_tags($ctPrice)));
      update_post_meta($postId, '_ct_agent_name', esc_attr(strip_tags($firstName." ".$lastName)));
      update_post_meta($postId, '_ct_price_prefix', esc_attr(strip_tags($customMetaPricePrefix)));
      update_post_meta($postId, '_ct_price_postfix', esc_attr(strip_tags($customMetaPricePostfix)));
      update_post_meta($postId, '_ct_sqft', esc_attr(strip_tags($customMetaSqFt)));
      update_post_meta($postId, '_ct_video', esc_attr(strip_tags($customMetaVideoURL)));
      update_post_meta($postId, '_ct_mls', esc_attr(strip_tags($customMetaMLS)));
      update_post_meta($postId, '_ct_latlng', esc_attr(strip_tags($customMetaLatLng)));
      update_post_meta($postId, '_ct_listing_expire', esc_attr(strip_tags($customMetaExpireListing)));
      update_post_meta($postId, '_ct_brokerage', 0);
      update_post_meta($postId, '_ct_global_energy_performance_index', esc_attr(strip_tags($customGlobalEnergyPerformanceIndex)));
      update_post_meta($postId, '_ct_renewable_energy_performance_index', esc_attr(strip_tags($customRenewableEnergyPerformanceIndex)));


      // Updates custom taxonomies
      $term_id = term_exists( $ctPropertySubtype, "property_type", $ctPropertyType ) ?: $ctPropertyType;
      wp_set_post_terms($postId, $term_id, 'property_type', FALSE);
      wp_set_post_terms($postId, $beds, 'beds', FALSE);
      wp_set_post_terms($postId, $customTaxBaths, 'baths', FALSE);
      wp_set_post_terms($postId, $customTaxState, 'state', FALSE);
      wp_set_post_terms($postId, $customTaxCity, 'city', FALSE);
      wp_set_post_terms($postId, $customTaxZip, 'zipcode', FALSE);
      wp_set_post_terms($postId, $customTaxCountry, 'country', FALSE);
      wp_set_post_terms($postId, $customTaxCommunity, 'community', FALSE);
      wp_set_post_terms($postId, $rooms, 'additional_features', FALSE);
    }
  }

  /**
   * Delete old listings
   *
   * @param $properties
   */
  private function deleteOldListingPost($properties, $mappings)
  {
    $parsedProperties = array();

    // Parse once for all the properties
    foreach ($properties as $property) {
      $parsedProperties[] = $this->parseJSONOutput($property, $mappings);
    }

    // Retrieve the current posts
    $posts = get_posts(array(
      'post_type' => 'listings',
      'numberposts' => -1,
    ));

    foreach ($posts as $post) {
      $postMustBeRemoved = true;

      // Verifies if the post exists
      foreach ($parsedProperties as $property) {
        $postTitle = $property['postTitle'][$this->siteLanguage];
        if ($postTitle == '') {
          foreach ($property['postTitle'] as $lang => $title) {
            $postTitle = $title;
          }
        }

        if ($postTitle == $post->post_title) {
          $postMustBeRemoved = false;
          break;
        }
      }

      // If not, we can execute the action
      if ($postMustBeRemoved) {
        // Delete the post
        wp_delete_post($post->ID);
      }
    }
  }

  /**
   * Verifies if a media is already posted or not for a given image URL.
   *
   * @access private
   * @param int $imageId
   * @return object
   */
  private function isMediaPosted($imageId)
  {
    $args = array(
      'post_type' => 'attachment',
      'posts_per_page' => -1,
      'post_status' => 'any',
      'content' => 'APIMO '.$imageId,
    );

    $medias = ApimoProrealestateSynchronizer_PostsByContent::get($args);

    if (isset($medias) && is_array($medias)) {
      foreach ($medias as $media) {
        return $media;
      }
    }

    return null;
  }

  /**
   * Return the filename for a given URL.
   *
   * @access private
   * @param string $imageUrl
   * @return string $filename
   */
  private function getFileNameFromURL($imageUrl)
  {
    $imageUrlData = pathinfo($imageUrl);
    return $imageUrlData['filename'];
  }

  /**
   * Calls the Apimo API
   *
   * @access private
   * @param string $url The API URL to call
   * @param string $method The HTTP method to use
   * @param array $body The JSON formatted body to send to the API
   * @return array $response
   */
  private function callApimoAPI($url, $method, $body = null)
  {
    $headers = array(
      'Authorization' => 'Basic ' . base64_encode(
          get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_provider'] . ':' .
          get_option('apimo_prorealestate_synchronizer_settings_options')['apimo_api_token']
        ),
      'content-type' => 'application/json',
    );

    if (null === $body || !is_array($body)) {
      $body = array();
    }

    if (!isset($body['limit'])) {
      $body['limit'] = 100;
    }
    if (!isset($body['offset'])) {
      $body['offset'] = 0;
    }

    $request = new WP_Http;
    $response = $request->request($url, array(
      'method' => $method,
      'headers' => $headers,
      'body' => $body,
      'sslverify' => false
    ));

    if (is_array($response) && !is_wp_error($response)) {
      $headers = $response['headers']; // array of http header lines
      $body = $response['body']; // use the content
    } else {
      $body = $response->get_error_message();
    }

    return array(
      'headers' => $headers,
      'body' => $body,
    );
  }

  /**
   * Activation hook
   */
  public function install()
  {
    if (!wp_next_scheduled('apimo_prorealestate_synchronizer_hourly_event')) {
      wp_schedule_event(time(), 'daily', 'apimo_prorealestate_synchronizer_hourly_event');
    }
  }

  /**
   * Deactivation hook
   */
  public function uninstall()
  {
    wp_clear_scheduled_hook('apimo_prorealestate_synchronizer_hourly_event');
  }
}
