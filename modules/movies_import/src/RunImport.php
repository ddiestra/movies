<?php

namespace Drupal\movies_import;

use Drupal\node\Entity\Node;
use GuzzleHttp\Exception\RequestException;
use Drupal\taxonomy\Entity\Term;
use Drupal\eck\Entity\EckEntity;

/**
 * RunImport Class.
 */
class RunImport {


  const API_KEY = 'e8db59d5ee0285069cd7ab86b877952b';
  const API_URL = 'https://api.themoviedb.org/3/';
  const IMG_URL = 'https://image.tmdb.org/t/p/w500';

  /**
   * Operation Callback.
   */
  public static function callback($movies, &$context) {

    $http_client = \Drupal::httpClient();
    $default_params = [
      'query' => [
        'api_key' => self::API_KEY,
      ],
    ];

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('movie_genre');

    foreach ($terms as $term) {
      $genres[$term->name] = $term->tid;
    }

    foreach ($movies as $movie) {

      $query = \Drupal::entityQuery('node')
        ->condition('status', 1)
        ->condition('type', 'movie')
        ->condition('field_external_id.value', $movie['id']);

      $nids = $query->execute();

      if (empty($nids)) {

        //Basic Information

        $node = [
          'type'   => 'movie',
          'title' => $movie['title'],
          'body'  => [
            'value' => $movie['overview'],
          ],
          'field_release_date' => $movie['release_date'],
          'field_popularity' => $movie['popularity'],
          'field_original_language' => $movie['original_language'],
          'field_external_id' => $movie['id'],
        ];

        if (!empty($movie['poster_path'])) {
          $node['field_poster'] = [
            'uri' => self::IMG_URL . $movie['poster_path'],
          ];
        }

        try {
          $request = $http_client->get(self::API_URL . "movie/{$movie['id']}", $default_params);

          $details = json_decode($request->getBody()->getContents(), TRUE);

          //Genres

          if (isset($details['genres']) && !empty($details['genres'])) {
            foreach ($details['genres'] as $k => $g) {

              if (!isset($genres[$g['name']])) {

                $term = Term::create([
                  'vid' => 'movie_genre',
                  'name' => $g['name'],
                ]);

                $term->enforceIsNew();
                $term->save();
                $genres[$g['name']] = $term->id();
              }

              $node['field_genre'][$k] = ['target_id' => $genres[$g['name']]];
            }
          }

          //Production Companies

          if (isset($details['production_companies']) && !empty($details['production_companies'])) {
            $node['field_production_companies'] = [];
            foreach ($details['production_companies'] as $k => $pc) {

              $query = \Drupal::entityQuery('node')
                ->condition('status', 1)
                ->condition('type', 'production_company')
                ->condition('field_external_id.value', $pc['id']);

              $pc_nids = $query->execute();

              if (!empty($pc_nids)) {
                $nid = array_pop($pc_nids);
              }
              else {
                $pc_node = Node::create([
                  'type'   => 'production_company',
                  'title' => $pc['name'],
                  'field_logo' => self::IMG_URL . $pc['logo_path'],
                  'field_external_id' => $pc['id'],
                ]);

                $pc_node->save();

                $nid = $pc_node->id();
              }

              $node['field_production_companies'][$k] = ['target_id' => $nid];
            }
          }
        }
        catch (RequestException $e) {
        }


        //Trailer
        try {
          $request = $http_client->get(self::API_URL . "movie/{$movie['id']}/videos", $default_params);

          $videos = json_decode($request->getBody()->getContents(), TRUE);

          if (isset($videos['results']) && !empty($videos['results'])) {
            foreach ($videos['results'] as $video) {
              if ($video['type'] == 'Trailer') {
                $url = ($video['site'] == 'YouTube') ? 'https://www.youtube.com/embed/' : '';
                $url = $url . $video['key'];
                $node['field_trailer'] = ['uri' => $url];
                break;
              }
            }
          }
        }
        catch (RequestException $e) {
        }

        //Images
        try {
          $request = $http_client->get(self::API_URL . "movie/{$movie['id']}/images", $default_params);

          $images = json_decode($request->getBody()->getContents(), TRUE);

          if (isset($images['posters']) && !empty($images['posters'])) {
            $node['field_gallery'] = [];
            foreach ($images['posters'] as $i => $image) {
              $node['field_gallery'][$i] = ['uri' => self::IMG_URL . $image['file_path']];
            }
          }
        }
        catch (RequestException $e) {
        }

        //Aternative Titles
        try {
          $request = $http_client->get(self::API_URL . "movie/{$movie['id']}/alternative_titles", $default_params);

          $titles = json_decode($request->getBody()->getContents(), TRUE);

          if (!empty($titles)) {
            $node['field_alternative_titles'] = [];
            foreach ($titles['titles'] as $i => $title) {
              $node['field_alternative_titles'][$i] = ['value' => $title['title']];
            }
          }
        }
        catch (RequestException $e) {
        }

        //Reviews

        try {
          $request = $http_client->get(self::API_URL . "movie/{$movie['id']}/reviews", $default_params);

          $reviews = json_decode($request->getBody()->getContents(), TRUE);

          if (isset($reviews['results']) && !empty($reviews['results'])) {
            $node['field_reviews'] = [];
            foreach ($reviews['results'] as $i => $review) {

              $brick_review = EckEntity::create([
                'title' => 'Review ' . $review['id'],
                'type' => 'review',
                'field_content' => $review['content'],
                'field_author' => $review['author']
              ]);
              $brick_review->save();
              $node['field_reviews'][$i] = ['target_id' => $brick_review->id()];
            }

          }
        }
        catch (RequestException $e) {
        }

        //Cast

        try {
          $request = $http_client->get(self::API_URL . "movie/{$movie['id']}/casts", $default_params);

          $casts = json_decode($request->getBody()->getContents(), TRUE);

          if (isset($casts['cast']) && !empty($casts['cast'])) {
            $node['field_cast'] = [];
            $k = 0;
            foreach ($casts['cast'] as $i => $person) {
              $cast = self::createActor($person);
              if ($cast) {
                $node['field_cast'][$k] = ['target_id' => $cast];
                $k++;
              }
            }
          }
        }
        catch (RequestException $e) {
        }

        $node = Node::create($node);
        $node->save();
        $context['results'][] = $node->id();
      }
    }
  }

  /**
   * Create Node Author.
   */
  public static function createActor($person) {

    $http_client = \Drupal::httpClient();
    $default_params = [
      'query' => [
        'api_key' => self::API_KEY,
      ],
    ];

    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', 'actor')
      ->condition('field_external_id.value', $person['id']);

    $nids = $query->execute();

    if (!empty($nids)) {
      $author = array_pop($nids);
    } else {
      $author = 0;
      try {
        $request = $http_client->get(self::API_URL . "person/{$person['id']}", $default_params);

        $person = json_decode($request->getBody()->getContents(), TRUE);

        $author = [
          'type'   => 'actor',
          'title' => $person['name'],
          'field_birthday' => $person['birthday'],
          'field_deathday' => $person['deathday'],
          'field_photo' => [
            'uri' => self::IMG_URL . $person['profile_path'],
          ],
          'field_place_birth' => $person['place_of_birth'],
          'field_popularity' => $person['popularity'],
          'body'  => [
            'value' => $person['biography'],
          ],
          'field_external_id' => $person['id'],
          'field_website' => $person['homepage'],
        ];

        //Images
        try {
          $request = $http_client->get(self::API_URL . "person/{$person['id']}/images", $default_params);

          $images = json_decode($request->getBody()->getContents(), TRUE);

          if (isset($images['profiles']) && !empty($images['profiles'])) {
            $author['field_gallery'] = [];
            foreach ($images['profiles'] as $i => $image) {

              if (!empty($image['file_path'])) {
                $author['field_gallery'][$i] = ['uri' => self::IMG_URL . $image['file_path']];
              }
            }
          }
        }
        catch (RequestException $e) {
        }

        $author = Node::create($author);
        $author->save();
        $author = $author->id();

      }
      catch (RequestException $e) {
      }
    }

    return $author;
  }

  /**
   * Operation Finished Callback.
   */
  public static function finished($success, $results, $operations) {

    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One Movie create.', '@count movies created.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }

    drupal_set_message($message);
  }

}
