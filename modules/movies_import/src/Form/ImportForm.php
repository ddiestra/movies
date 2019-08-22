<?php

namespace Drupal\movies_import\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Import form.
 */
class ImportForm extends FormBase {


  const API_KEY = 'e8db59d5ee0285069cd7ab86b877952b';
  const API_URL = 'https://api.themoviedb.org/3/';

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Guzzle\Client instance.
   *
   * @var \Guzzle\Client
   */
  protected $httpClient;


  /**
   * Drupal\Core\Config\ConfigFactoryInterface instance.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   *
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Client $http_client, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('http_client'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'import_movies_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['content'] = ['#type' => 'container'];

    $message = $this->t('These will run the import for all the movies of the one month of the last year and the actors related.');

    $month = $this->configFactory->getEditable('movies_import.settings')->get('last_month') ?: 1;

    $date       = \DateTime::createFromFormat('!m', $month);
    $month_name = $date->format('F');

    $message .= $this->t('Month to Import: @month', ['@month' => $month_name]);

    $form['content']['text'] = [
      '#markup' => '<p>' . $message . '</p>',
    ];

    $form['actions']['#type'] = 'actions';

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $month = $this->configFactory->getEditable('movies_import.settings')->get('last_month')?: 1;
    $month = 1;
    $this->configFactory->getEditable('movies_import.settings')->set('last_month', $month + 1)->save();
    $date = \DateTime::createFromFormat('Y-m', date('Y') . '-' . $month);
    $gte = $date->format('Y-m-01');
    $lte = $date->format('Y-m-t');

    $query = [
      'api_key' => self::API_KEY,
      'year' => date('Y'),
      'release_date.gte' => $gte,
      'release_date.lte' => $lte,
    ];

    $limit = 100;
    $page = 1;

    $batch = [
      'title' => t('Running Import...'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\movies_import\RunImport::finished',
    ];

    while (TRUE) {

      $query['page'] = $page;

      $movies = $this->getNextPage($query);

      if (isset($movies['results']) && !empty($movies['results'])) {

        $batch['operations'][] = [
          '\Drupal\movies_import\RunImport::callback',
          [$movies['results']],
        ];

        if ($movies['page'] == $movies['total_pages']) {
          break;
        }
      }
      else {
        break;
      }

      $limit--;
      $page++;
      if (!$limit) {
        break;
      }
    }

    if (!empty($batch['operations'])) {
      batch_set($batch);
    }
    else {
      drupal_set_message($this->t('Nothing to import.'), 'error');
    }
  }

  /**
   *
   */
  private function getNextPage($query) {

    try {
      $request = $this->httpClient->request('GET', self::API_URL . 'discover/movie', ['query' => $query]);
      $statusCode = $request->getStatusCode();
    }
    catch (RequestException $e) {
      $statusCode = 0;
    }

    if ($statusCode == 200) {
      $movies = json_decode($request->getBody()->getContents(), TRUE);
      return $movies;
    }

    return [];
  }

}
