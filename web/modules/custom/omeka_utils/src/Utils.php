<?php

namespace Drupal\omeka_utils;

use Drupal\Core\Cache\Cache;

#[\AllowDynamicProperties]
class Utils {

  function __construct() {
    $config = \Drupal::config('dog.settings');
    $this->base_url = $config->get('base_url');
    $this->url = $this->base_url . 'api/items/';
    $this->expire = strtotime('now +1 week');
  }

  /**
   *$omeka = \Drupal::service('dh_omeka.utils');
   */
  public function getItem($id) {
    $omeka_id = $this->url . $id;
    $cacheId = 'omeka_item_' . $id;
    if ($cache = \Drupal::cache('omeka')->get($cacheId)) {
      return $cache->data;
    }
    else {
      $omeka_item_source = file_get_contents($omeka_id);
      $omeka_item = json_decode($omeka_item_source);
      \Drupal::cache('omeka')->set($cacheId, $omeka_item, $this->expire);
      return $omeka_item;
    }
  }

  public function getDescription($item) {
    $template = $this->getResourceTemplate($item);
    $description_fields = [
      '2' => 'oad:scopeAndContent',
      '4' => 'oad:scopeAndContent',
      '5' => 'oad:scopeAndContent',
      '6' => 'dcterms:description',
      '7' => 'bibo:content',
    ];

    $description_field = $description_fields[$template];
    $descrizione = $item->{$description_field};
    return $descrizione[0]->{'@value'};
  }

  public function getTitle($item) {
    $title = $item->{'dcterms:title'};
    return $title[0]->{'@value'};
  }

  public function getIdFromEck($entity) {
    $id = $entity->get('field_id')->getValue();
    return $id[0]['value'];
  }

  public function getImage($item, $type = 'large') {
    $medias = $item->{"o:media"};
    if (!empty($medias)) {
      $media_url = $medias[0]->{"@id"};
      $media_source = file_get_contents($media_url);
      $media = json_decode($media_source);
      $image_src = $media->thumbnail_display_urls->{$type};
      return $image_src;
    }
  }

  public function getResourceTemplate($item) {
    $resource_id = $item->{'o:resource_template'};
    return $resource_id->{"o:id"};
  }

  public function getLatLon($item) {
    $marker_url = $item->{'o-module-mapping:marker'};
    $marker_object = file_get_contents($marker_url[0]->{'@id'});
    $marker = json_decode($marker_object);
    $values['lat'] = $marker->{'o-module-mapping:lat'};
    $values['lon'] = $marker->{'o-module-mapping:lng'};
    $values['title'] = $marker->{'o-module-mapping:label'};
    $values['url'] = 'https://risorse.dh.unica.it/s/400-risorse/item/' . $item->{'o:id'};
    $values['image'] = $this->getImage($item);
    return $values;
  }

  public function getLocation($omeka_item_full) {
    // Verifica che il campo mapping feature esista
    if (empty($omeka_item_full->{'o-module-mapping:feature'})) {
      \Drupal::logger('omeka_utils')->warning('Item Omeka senza mapping features: @id', [
        '@id' => $omeka_item_full['o:id'] ?? 'ID sconosciuto',
      ]);
      return [
        'latitude' => null,
        'longitude' => null,
      ];
    }
    
    // Prova prima a recuperare le coordinate direttamente dall'oggetto
    if (isset($omeka_item_full->{'o-module-mapping:feature'}[0]->{'o-module-mapping:geography-coordinates'})) {
      $coordinates = $omeka_item_full->{'o-module-mapping:feature'}[0]->{'o-module-mapping:geography-coordinates'};
      \Drupal::logger('omeka_utils')->debug('Coordinate trovate direttamente nell\'oggetto: @coords', [
        '@coords' => json_encode($coordinates),
      ]);
      return [
        'latitude' => $coordinates[1],
        'longitude' => $coordinates[0],
      ];
    }
    
    // Se non ci sono coordinate dirette, prova con l'approccio precedente
    $location_url = $omeka_item_full->{'o-module-mapping:feature'}[0]->{'@id'} ?? '';
    if (empty($location_url)) {
      \Drupal::logger('omeka_utils')->warning('URL mapping feature vuoto per item: @id', [
        '@id' => $omeka_item_full['o:id'] ?? 'ID sconosciuto',
      ]);
      return [
        'latitude' => null,
        'longitude' => null,
      ];
    }
    
    try {
      $location_data = file_get_contents($location_url);
      $location_json = json_decode($location_data, true);

      // Extract coordinates from the JSON
      $coordinates = $location_json['o-module-mapping:geography-coordinates'] ?? null;
      if ($coordinates) {
        return [
          'latitude' => $coordinates[1],
          'longitude' => $coordinates[0],
        ];
      }
    } catch (\Exception $e) {
      \Drupal::logger('omeka_utils')->error('Errore nel recupero coordinate per item @id: @error', [
        '@id' => $omeka_item_full['o:id'] ?? 'ID sconosciuto',
        '@error' => $e->getMessage(),
      ]);
    }
    
    // Se arriviamo qui, non siamo riusciti a recuperare le coordinate
    return [
      'latitude' => null,
      'longitude' => null,
    ];
  }

  public function getResourceName($resource_id) {
    $resources = [
      '1' => 'Base Resource',
      '2' => 'Risorsa fotografica',
      '3' => 'Risorsa archeologica',
      '4' => 'Risorsa cartografica',
      '5' => 'Risorsa documentale',
      '6' => 'Risorsa opera d\'arte',
      '7' => 'Risorsa bibliografica',
      '8' => 'Risorsa audio',
    ];
    return $resources[$resource_id];
  }

  public function getItemUrl($item) {
    $site_url = $this->getSiteUrl($item);
    return $site_url . '/item/' . $item->{'o:id'};
  }

  public function getSiteUrl($item) {
    $cacheId = 'omeka_site_' . $item->{'o:id'};
    if ($cache = \Drupal::cache('omeka')->get($cacheId)) {
      return $cache->data;
    }

    // Rimuovo il trailing slash dalla base_url per evitare il doppio slash.
    $base_url_clean = rtrim($this->base_url, '/');
    $site_id = $item->{'o:site'} ?? [];

    // Un oggetto Omeka non assegnato ad alcun sito ha 'o:site' vuoto (o senza
    // '@id'): in quel caso file_get_contents() riceverebbe un path vuoto e
    // lancerebbe un ValueError, mandando in errore 500 l'intera pagina che
    // renderizza il blocco (gallery, map, map_timeline). Gestiamo il caso con
    // un URL di fallback sulla base_url e un warning, così la pagina resta viva.
    if (empty($site_id) || empty($site_id[0]->{'@id'})) {
      \Drupal::logger('omeka_utils')->warning('Oggetto Omeka @id senza sito associato (o:site vuoto): uso URL di fallback sulla base_url.', [
        '@id' => $item->{'o:id'} ?? 'sconosciuto',
      ]);
      $site_url = $base_url_clean;
      $expire = strtotime('now +1 week');
      \Drupal::cache('omeka')->set($cacheId, $site_url, $expire);
      return $site_url;
    }

    $site_source = file_get_contents($site_id[0]->{'@id'});
    $site = json_decode($site_source);
    $slug = $site->{'o:slug'};
    $site_url = $base_url_clean . '/s/' . $slug;
    $expire = strtotime('now +1 week');
    \Drupal::cache('omeka')->set($cacheId, $site_url, $expire);
    return $site_url;
  }

  // JavaScript code removed

}

