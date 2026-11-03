<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\cloudflare_purge\CredentialResolverInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modernized configuration form for Cloudflare Purge module.
 *
 * Features:
 * - Bearer Token as default (modern approach)
 * - Legacy API Key/Email mode for backward compatibility
 * - Storage method selection (Plain text vs Key module)
 * - Automatic detection of existing user configuration
 * - settings.php override with config cleanup
 * - Smart credential cleanup when switching methods.
 */
final class CloudflarePurgeForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @deprecated in cloudflare_purge:3.1.0 and is removed from
   *   cloudflare_purge:4.0.0. Use
   *   \Drupal\cloudflare_purge\CloudflarePurgeSettings::NAME instead.
   *
   * @see https://www.drupal.org/node/3600858
   */
  public const SETTINGS = CloudflarePurgeSettings::NAME;

  /**
   * Bearer-token authentication method.
   *
   * @deprecated in cloudflare_purge:3.1.0 and is removed from
   *   cloudflare_purge:4.0.0. Use
   *   \Drupal\cloudflare_purge\CloudflarePurgeSettings::AUTH_BEARER instead.
   *
   * @see https://www.drupal.org/node/3600858
   */
  public const AUTH_BEARER = CloudflarePurgeSettings::AUTH_BEARER;

  /**
   * Legacy API key/email authentication method.
   *
   * @deprecated in cloudflare_purge:3.1.0 and is removed from
   *   cloudflare_purge:4.0.0. Use
   *   \Drupal\cloudflare_purge\CloudflarePurgeSettings::AUTH_LEGACY instead.
   *
   * @see https://www.drupal.org/node/3600858
   */
  public const AUTH_LEGACY = CloudflarePurgeSettings::AUTH_LEGACY;

  /**
   * Plain-text credential storage method.
   *
   * @deprecated in cloudflare_purge:3.1.0 and is removed from
   *   cloudflare_purge:4.0.0. Use
   *   \Drupal\cloudflare_purge\CloudflarePurgeSettings::STORAGE_PLAIN instead.
   *
   * @see https://www.drupal.org/node/3600858
   */
  public const STORAGE_PLAIN = CloudflarePurgeSettings::STORAGE_PLAIN;

  /**
   * Key-module credential storage method.
   *
   * @deprecated in cloudflare_purge:3.1.0 and is removed from
   *   cloudflare_purge:4.0.0. Use
   *   \Drupal\cloudflare_purge\CloudflarePurgeSettings::STORAGE_KEY instead.
   *
   * @see https://www.drupal.org/node/3600858
   */
  public const STORAGE_KEY = CloudflarePurgeSettings::STORAGE_KEY;

  /**
   * Constructs a CloudflarePurgeForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel for this module.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service, used to resolve the module path.
   * @param \Drupal\cloudflare_purge\CredentialResolverInterface $credentialResolver
   *   The credential resolver.
   * @param \Drupal\key\KeyRepositoryInterface|null $keyRepository
   *   (optional) The Key module repository service, if available.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected LoggerChannelInterface $logger,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected CredentialResolverInterface $credentialResolver,
    protected ?KeyRepositoryInterface $keyRepository = NULL,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('logger.factory')->get('cloudflare_purge'),
      $container->get('module_handler'),
      $container->get('extension.list.module'),
      $container->get('cloudflare_purge.credential_resolver'),
      $container->has('key.repository') ? $container->get('key.repository') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [CloudflarePurgeSettings::NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cloudflare_purge_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(CloudflarePurgeSettings::NAME);

    // Attach CSS library.
    $form['#attached']['library'][] = 'cloudflare_purge/form';
    // Attach the navigation library at form level too. CloudflarePurgeLinks
    // also attaches it, but on a sub-element carrying its own #cache metadata
    // whose bubbled #attached can be served stale from the render cache,
    // dropping the stylesheet. Attaching here guarantees the tab strip is
    // always styled.
    $form['#attached']['library'][] = 'cloudflare_purge/navigation';
    $form['#attributes']['class'][] = 'cloudflare-purge-form';
    $form['cloudflare_purge_nav'] = CloudflarePurgeLinks::build($this->getRouteMatch()->getRouteName());

    // Check if credentials are overridden in settings.php.
    if ($this->hasSettingsOverride()) {
      return $this->buildOverrideMessage($form);
    }

    // Check for incomplete settings.php override.
    if ($this->hasIncompleteSettingsOverride()) {
      $this->messenger()->addWarning($this->t(
        'Incomplete credentials detected in <code>settings.php</code>. You have partial configuration in <code>$settings["cloudflare_purge_credentials"]</code> but not a complete set. Please either:<ul><li>Add missing credentials to settings.php (zone_id + bearer_token OR zone_id + email + authorization)</li><li>Remove the partial configuration from settings.php and use this form instead</li></ul>Current form values will be used until settings.php contains a complete set.'
      ));
    }

    // Detect current state.
    $auth_method = $this->detectAuthMethod();
    $storage_method = $this->detectStorageMethod();

    // ========== TWO COLUMN LAYOUT ==========
    $form['layout'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cloudflare-purge-layout', 'cfp-layout']],
    ];

    // ========== LEFT COLUMN: Configuration Options ==========
    $form['layout']['left'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('How you sign in'),
      '#description' => $this->t('Pick the authentication method and where the secret is kept.'),
      '#attributes' => ['class' => ['cloudflare-purge-config-panel', 'cfp-section', 'cfp-section--signin']],
    ];

    // Authentication Method (in left column)
    $form['layout']['left']['auth_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Authentication Method'),
      '#options' => [
        CloudflarePurgeSettings::AUTH_BEARER => $this->t('Bearer Token'),
        CloudflarePurgeSettings::AUTH_LEGACY => $this->t('Legacy API Key/Email'),
      ],
      '#default_value' => $auth_method,
      '#description' => $this->t('Bearer Token is recommended: a scoped API token. Legacy mode uses the global API key and account email.'),
      '#attributes' => ['class' => ['cloudflare-purge-radios']],
    ];

    // Storage Method (in left column)
    $storage_options = [
      CloudflarePurgeSettings::STORAGE_PLAIN => $this->t('In the database (plain text)'),
    ];

    if ($this->moduleHandler->moduleExists('key')) {
      $storage_options[CloudflarePurgeSettings::STORAGE_KEY] = $this->t('Key module');
    }

    $form['layout']['left']['storage_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storage Method'),
      '#options' => $storage_options,
      '#default_value' => $storage_method,
      '#description' => $this->t('Choose how to store your credentials.'),
      '#attributes' => ['class' => ['cloudflare-purge-radios']],
    ];

    // Security warning for plain text storage.
    $form['layout']['left']['plain_text_warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="cfp-callout cfp-callout--warning" role="note">' .
      '<strong>' . $this->t('Security Warning:') . '</strong> ' .
      $this->t('Plain text storage saves credentials in the database. For production environments, use settings.php override or the Key module for secure storage.') .
      '</div>',
      '#states' => [
        'visible' => [
          ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
        ],
      ],
    ];

    if (!$this->moduleHandler->moduleExists('key')) {
      $form['layout']['left']['key_module_notice'] = [
        '#markup' => '<div class="cfp-callout cfp-callout--info cloudflare-purge-notice" role="note">' .
        $this->t('Install the <a href="@url" target="_blank">Key module</a> to enable secure credential storage.', [
          '@url' => 'https://www.drupal.org/project/key',
        ]) . '</div>',
      ];
    }

    // ========== RIGHT COLUMN: Credentials ==========
    $form['layout']['right'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cloudflare zone and token'),
      '#description' => $this->t('When storing credentials in <code>settings.php</code>, provide a complete set (zone_id + bearer_token, or zone_id + email + authorization); this form is then disabled.'),
      '#attributes' => ['class' => ['cloudflare-purge-credentials-panel', 'cfp-section', 'cfp-section--zone']],
    ];

    // Zone ID - Plain text.
    $form['layout']['right']['zone_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Zone ID'),
      '#size' => 60,
      '#default_value' => $config->get('zone_id'),
      '#description' => $this->t('Enter your Cloudflare Zone ID.'),
      '#states' => [
        'visible' => [
          ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
        ],
        'required' => [
          ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
        ],
      ],
    ];

    // Zone ID - Key module.
    if ($this->moduleHandler->moduleExists('key')) {
      $form['layout']['right']['zone_id_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Zone ID'),
        '#default_value' => $config->get('zone_id_key'),
        '#description' => $this->t('Select the key containing your Cloudflare Zone ID.'),
        '#states' => [
          'visible' => [
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
          'required' => [
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
        ],
      ];
    }

    // Bearer Token - Plain text. Secret fields never send the saved value
    // back to the browser; a blank field on save keeps the saved value.
    $has_bearer = (string) ($config->get('bearer_token') ?? '') !== '';
    $bearer_when = [
      ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_BEARER],
      ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
    ];
    $form['layout']['right']['bearer_token'] = [
      '#type' => 'password',
      '#title' => $this->t('API token'),
      '#size' => 60,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'placeholder' => $has_bearer ? $this->t('Saved. Leave blank to keep it.') : '',
      ],
      '#description' => $has_bearer
        ? $this->t('A token is saved and hidden. Enter a new one only to replace it.')
        : $this->t('Enter your Cloudflare Bearer Token (API Token).'),
      '#states' => ['visible' => $bearer_when] + ($has_bearer ? [] : ['required' => $bearer_when]),
    ];

    // Bearer Token - Key module.
    if ($this->moduleHandler->moduleExists('key')) {
      $form['layout']['right']['bearer_token_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('API token'),
        '#default_value' => $config->get('bearer_token_key'),
        '#description' => $this->t('Select the key containing your Cloudflare Bearer Token.'),
        '#states' => [
          'visible' => [
            ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_BEARER],
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
          'required' => [
            ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_BEARER],
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
        ],
      ];
    }

    // Email - Plain text.
    $form['layout']['right']['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Account email'),
      '#size' => 60,
      '#default_value' => $config->get('email'),
      '#description' => $this->t('Enter your Cloudflare account email address.'),
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
          ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
        ],
        'required' => [
          ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
          ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
        ],
      ],
    ];

    // Email - Key module.
    if ($this->moduleHandler->moduleExists('key')) {
      $form['layout']['right']['email_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Account email'),
        '#default_value' => $config->get('email_key'),
        '#description' => $this->t('Select the key containing your Cloudflare email.'),
        '#states' => [
          'visible' => [
            ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
          'required' => [
            ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
        ],
      ];
    }

    // Authorization - Plain text. Secret: never sent back to the browser.
    $has_authorization = (string) ($config->get('authorization') ?? '') !== '';
    $authorization_when = [
      ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
      ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_PLAIN],
    ];
    $form['layout']['right']['authorization'] = [
      '#type' => 'password',
      '#title' => $this->t('Global API key'),
      '#size' => 60,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'placeholder' => $has_authorization ? $this->t('Saved. Leave blank to keep it.') : '',
      ],
      '#description' => $has_authorization
        ? $this->t('A key is saved and hidden. Enter a new one only to replace it.')
        : $this->t('Enter your Cloudflare Global API Key.'),
      '#states' => ['visible' => $authorization_when] + ($has_authorization ? [] : ['required' => $authorization_when]),
    ];

    // Authorization - Key module.
    if ($this->moduleHandler->moduleExists('key')) {
      $form['layout']['right']['authorization_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Global API key'),
        '#default_value' => $config->get('authorization_key'),
        '#description' => $this->t('Select the key containing your Cloudflare API Key.'),
        '#states' => [
          'visible' => [
            ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
          'required' => [
            ':input[name="auth_method"]' => ['value' => CloudflarePurgeSettings::AUTH_LEGACY],
            ':input[name="storage_method"]' => ['value' => CloudflarePurgeSettings::STORAGE_KEY],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Skip validation if settings.php override is in place.
    if ($this->hasSettingsOverride()) {
      return;
    }

    $storage_method = $form_state->getValue('storage_method');

    // The secret fields are blank on every page load, so require one only
    // when nothing is saved for it yet (the #states rule needs JavaScript).
    if ($storage_method === CloudflarePurgeSettings::STORAGE_PLAIN) {
      $secret = $form_state->getValue('auth_method') === CloudflarePurgeSettings::AUTH_BEARER ? 'bearer_token' : 'authorization';
      $saved = (string) ($this->config(CloudflarePurgeSettings::NAME)->get($secret) ?? '');
      if (trim((string) $form_state->getValue($secret)) === '' && $saved === '') {
        $form_state->setErrorByName($secret, $secret === 'bearer_token'
          ? $this->t('API token is required.')
          : $this->t('Global API key is required.'));
      }
    }

    // Validate Zone ID format (only for plain text storage).
    if ($storage_method === CloudflarePurgeSettings::STORAGE_PLAIN) {
      $zone_id = $form_state->getValue('zone_id');
      if (is_string($zone_id) && $zone_id !== '') {
        if (!preg_match(CloudflarePurgeApi::ZONE_ID_PATTERN, $zone_id)) {
          $form_state->setErrorByName('zone_id', $this->t('Invalid Zone ID format. Zone ID should be a 32-character hexadecimal string. You can find it in your Cloudflare dashboard.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(CloudflarePurgeSettings::NAME);
    $auth_method = $form_state->getValue('auth_method');
    $storage_method = $form_state->getValue('storage_method');

    // If settings.php override exists, clean ALL config and return.
    if ($this->hasSettingsOverride()) {
      $this->cleanAllConfig();
      $this->messenger()->addStatus($this->t('Configuration cleaned. Credentials are managed via settings.php.'));
      parent::submitForm($form, $form_state);
      return;
    }

    // Save authentication and storage method preferences.
    $config->set('auth_method', $auth_method);
    $config->set('storage_method', $storage_method);

    // Determine which credentials to save based on authentication method.
    if ($auth_method === CloudflarePurgeSettings::AUTH_BEARER) {
      $active_fields = ['zone_id', 'bearer_token'];
      $inactive_fields = ['email', 'authorization'];
    }
    else {
      $active_fields = ['zone_id', 'email', 'authorization'];
      $inactive_fields = ['bearer_token'];
    }

    // Save active credentials based on storage method.
    foreach ($active_fields as $field) {
      if ($storage_method === CloudflarePurgeSettings::STORAGE_KEY) {
        // Get value from {field}_key form element.
        $key_field = $field . '_key';
        $value = $form_state->getValue($key_field);
        if ($value !== NULL) {
          $config->set($key_field, $value);
        }
        // Clear plain text version.
        $config->clear($field);
      }
      else {
        // Get value from plain field. A blank secret field keeps the saved
        // secret, because the form never shows it.
        $value = $form_state->getValue($field);
        $is_secret = in_array($field, ['bearer_token', 'authorization'], TRUE);
        if ($is_secret) {
          // Trim a pasted trailing newline; blank keeps the saved secret.
          $value = trim((string) $value);
          $value = $value === '' ? NULL : $value;
        }
        if ($value !== NULL) {
          $config->set($field, $value);
        }
        // Clear key version.
        $config->clear($field . '_key');
      }
    }

    // Clean inactive credentials (from opposite auth method) -
    // both plain and key.
    foreach ($inactive_fields as $field) {
      $config->clear($field);
      $config->clear($field . '_key');
    }

    $config->save();

    $this->logger->info('Cloudflare Purge configuration updated. Auth: @auth, Storage: @storage', [
      '@auth' => $auth_method,
      '@storage' => $storage_method,
    ]);

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds form when settings.php override is detected.
   *
   * @param array $form
   *   The form array.
   *
   * @return array
   *   The form with override message.
   */
  protected function buildOverrideMessage(array $form): array {
    $auth_type = match ($this->credentialResolver->getSettingsOverrideAuthType()) {
      CloudflarePurgeSettings::AUTH_BEARER => $this->t('Bearer Token'),
      CloudflarePurgeSettings::AUTH_LEGACY => $this->t('Legacy API Key/Email'),
      default => $this->t('Unknown'),
    };

    $form['override_message'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cfp-locked'], 'role' => 'note'],
    ];

    $form['override_message']['content'] = [
      '#markup' => '<div class="cloudflare-purge-override-message">' .
      '<p class="cfp-locked__title">' . $this->t('Managed in settings.php') . '</p>' .
      '<p>' . $this->t('Your Cloudflare credentials are currently managed via <code>settings.php</code>. This is the recommended approach for production environments.') . '</p>' .
      '<p><strong>' . $this->t('Detected configuration:') . '</strong> ' . $auth_type . '</p>' .
      '<p>' . $this->t('To modify credentials, update the <code>$settings["cloudflare_purge_credentials"]</code> array in your <code>settings.php</code> file.') . '</p>' .
      '<p>' . $this->t('All database-stored credentials have been disabled and will be cleaned on form submission for security.') . '</p>' .
      '</div>',
    ];

    return $form;
  }

  /**
   * Detects the authentication method for existing users.
   *
   * Priority:
   * 1. Check saved preference in config
   * 2. Detect from existing credentials
   * 3. Default to Bearer for new users.
   *
   * @return string
   *   AUTH_BEARER or AUTH_LEGACY constant.
   */
  protected function detectAuthMethod(): string {
    return $this->credentialResolver->detectAuthMethod();
  }

  /**
   * Detects the storage method for existing users.
   *
   * Priority:
   * 1. Check saved preference in config
   * 2. Detect from existing credentials (check for _key suffix)
   * 3. Default to Key module if installed, otherwise Plain.
   *
   * @return string
   *   STORAGE_PLAIN or STORAGE_KEY constant.
   */
  protected function detectStorageMethod(): string {
    return $this->credentialResolver->detectStorageMethod();
  }

  /**
   * Cleans ALL credentials from config.
   *
   * Used when settings.php override is detected.
   */
  protected function cleanAllConfig(): void {
    $config = $this->config(CloudflarePurgeSettings::NAME);
    $all_fields = ['zone_id', 'bearer_token', 'email', 'authorization'];

    foreach ($all_fields as $field) {
      $config->clear($field);
      $config->clear($field . '_key');
    }

    // Clear method preferences too.
    $config->clear('auth_method');
    $config->clear('storage_method');

    $config->save();

    $this->logger->info('All Cloudflare Purge configuration cleaned due to settings.php override.');
  }

  /**
   * Checks if credentials are overridden in settings.php.
   *
   * Only returns TRUE if a COMPLETE and VALID set of credentials exists.
   * This prevents the form from being disabled when settings.php has
   * incomplete credentials.
   *
   * @return bool
   *   TRUE if complete override exists, FALSE otherwise.
   */
  protected function hasSettingsOverride(): bool {
    return $this->credentialResolver->hasSettingsOverride();
  }

  /**
   * Checks if settings.php has incomplete credentials.
   *
   * @return bool
   *   TRUE if incomplete credentials detected, FALSE otherwise.
   */
  protected function hasIncompleteSettingsOverride(): bool {
    return $this->credentialResolver->hasIncompleteSettingsOverride();
  }

}
