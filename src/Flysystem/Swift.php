<?php

namespace Drupal\flysystem_swift\Flysystem;

use Drupal\Component\Utility\Random;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\flysystem\Plugin\FlysystemPluginInterface;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Drupal\flysystem_swift\SwiftAdapter;
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
   * @return \Drupal\flysystem_swift\SwiftAdapter
   */
  public function getAdapter() {
    $client = new OpenStack($this->configuration);
    $container = $client->objectStoreV1()
      ->getContainer($this->configuration['container']);
    return new SwiftAdapter($container);
  }

  /**
   * Retrieves a temporary URL from the Object Storage endpoint.
   *
   * Use with caution when the resulting URL may be cached beyond its validity.
   *
   * @param string $uri The uri to retrieve a temporary URI for.
   */
  public function getTemporaryUrl($uri) {
    $uri = str_replace('\\', '/', $this->getTarget($uri));
    $client = new OpenStack($this->configuration);
    // Base the state storage on the hash of the plugin config.
    $hash = md5(serialize($this->configuration));
    if (!$config = $this->state->get('flysystem_swift.' . $hash)) {
      $account = $client->objectStoreV1()->getAccount();
      $account->retrieve();
      if (!$key = $account->tempUrl) {
        $random = new Random();
        $key = $random->string(32);
        $client->objectStoreV1()
          ->execute($this->api->postAccount(), ['tempUrlKey' => $key]);
      }
      // The only way to get a base path/service URL is from a Token.
      $token = $client->identityV3()->generateToken(['user' => $this->configuration['user']]);
      $basePath = $token->catalog
        ->getServiceUrl('swift', 'object-store', $this->configuration['region'], 'public');
      $this->state->set('flysystem_swift.' . $hash, ['key' => $key, 'basePath' => $basePath]);
    }
    else {
      extract($config);
    }
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
      $errors[] = [
        'severity' => RfcLogLevel::ERROR,
        'message' => $e->getMessage(),
        'context' => [],
      ];
    }
    return $errors;
  }

}
