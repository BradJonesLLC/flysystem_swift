<?php

namespace Drupal\flysystem_swift\Flysystem;

use Drupal\Component\Utility\Random;
use Drupal\Console\Command\User\PasswordHashCommand;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\flysystem\Plugin\FlysystemPluginInterface;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Nimbusoft\Flysystem\OpenStack\SwiftAdapter;
use OpenStack\Identity\v3\Models\Catalog;
use OpenStack\ObjectStore\v1\Api;
use OpenStack\OpenStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drupal plugin for Swift Flysystem adapter.
 * 
 * @Adapter(id = "swift")
 */
class Swift implements FlysystemPluginInterface, ContainerFactoryPluginInterface {

  use FlysystemUrlTrait;

  /**
   * State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Plugin configuration.
   * 
   * @var array
   */
  protected $configuration;

  /**
   * The Object Store API definitions.
   *
   * @var \OpenStack\ObjectStore\v1\Api
   */
  protected $api;

  /**
   * @inheritDoc
   */
  public function __construct(array $configuration, StateInterface $state) {
    $this->configuration = $configuration;
    $this->state = $state;
    $this->api = new Api();
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $container->get('state')
    );
  }

  /**
   * @return SwiftAdapter
   */
  public function getAdapter() {
    $client = new OpenStack($this->configuration);
    $container = $client->objectStoreV1()
      ->getContainer($this->configuration['container']);
    return new SwiftAdapter($container);
  }

  /**
   * Retrieves a temporary URL from the Object Storage endpoint.
   */
  public function getExternalUrl($uri) {
    $uri = str_replace('\\', '/', $this->getTarget($uri));
    $client = new OpenStack($this->configuration);
    // @todo - Tie the state storage to a hash of the config?
    // @todo - Do we need to handle rotation?
    if (!$key = $this->state->get('flysystem_swift.tempkey')) {
      $account = $client->objectStoreV1()->getAccount();
      $account->retrieve();
      if (!$key = $account->tempUrl) {
        $random = new Random();
        $key = $random->string(32);
        $client->objectStoreV1()
          ->execute($this->api->postAccount(), ['tempUrlKey' => $key]);
      }
      $this->state->set('flysystem_swift.tempkey', $key);
    }
    // The only way to get a base path/service URL is from a Token.
    $token = $client->identityV3()->generateToken(['user' => $this->configuration['user']]);
    $basePath = $token->catalog
      ->getServiceUrl('swift', 'object-store', $this->configuration['region'], 'public');
    $path = parse_url($basePath)['path'];
    $resource = '/' . $this->configuration['container'] . '/' . $uri;
    $url = Url::fromUri($basePath . $resource, ['query' => $this->generateTempQuery($path . $resource, $key)]);
    return $url->toString();
  }

  private function generateTempQuery(string $path, string $key, int $length = 300, string $method = 'GET') {
    $expires = intval(time() + $length);
    return [
      'temp_url_sig' => hash_hmac('sha1', "$method\n$expires\n$path", $key),
      'temp_url_expires' => $expires,
    ];
  }

  /**
   * @inheritDoc
   */
  public function ensure($force = FALSE) {
    $errors = [];
    $client = new OpenStack($this->configuration);
    try {
      $client->objectStoreV1()
        ->execute($this->api->getContainer(),
          ['name' => $this->configuration['container']]);
    }
    catch (\Throwable $e) {
      $errors[] = $e->getCode() . ': ' . $e->getMessage();
    }
    return $errors;
  }

}
