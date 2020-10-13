<?php

namespace Drupal\webform_strawberryfield\Plugin\WebformHandler;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\TempStoreException;
use Drupal\webform\Plugin\WebformElement\WebformManagedFileBase;
use Drupal\webform\Plugin\WebformElementEntityReferenceInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\webformSubmissionInterface;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\FileInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\webform\Element\WebformOtherBase;
use Drupal\Core\Field\BaseFieldDefinition;



/**
 * Form submission handler when Webform is used as strawberyfield widget.
 *
 * @WebformHandler(
 *   id = "strawberryField_webform_handler",
 *   label = @Translation("A strawberryField harvester"),
 *   category = @Translation("Form Handler"),
 *   description = @Translation("StrawberryField Harvester"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class strawberryFieldharvester extends WebformHandlerBase {

    /**
     * @var bool
     */
    private $isWidgetDriven = FALSE;

    /**
     * The entityTypeManager factory.
     *
     * @var $entityTypeManage EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * @var \Drupal\webform\WebformTokenManagerInterface
     */
    protected $tokenManager;

    /**
     * @var \Drupal\Core\File\FileSystemInterface
     */
    protected $fileSystem;

    /**
     * @var \Drupal\file\FileUsage\FileUsageInterface
     */
    protected $fileUsage;

    /**
     * @var \Drupal\Component\Transliteration\TransliterationInterface
     */
    protected $transliteration;

    /**
     * @var \Drupal\Core\Language\LanguageManagerInterface
     */
    protected $languageManager;

    /**
     * The webform element manager.
     *
     * @var \Drupal\webform\Plugin\WebformElementManagerInterface
     */
    protected $webformElementManager;

    /**
     * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
     */
    protected $entityTypeBundleInfo;

    /**
     * The entity field manager.
     *
     * @var \Drupal\Core\Entity\EntityFieldManager|null
     */
    protected $entityFieldManager;

    /**
     * The Strawberry Field Utility Service.
     *
     * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
     */
    protected $strawberryfieldUtility;
    /**
     * Internal storage for overriden Webform Settings
     *
     * @var array
     */
    protected $customWebformSettings = [];

    /**
     * The current user.
     *
     * @var \Drupal\Core\Session\AccountInterface
     */
    protected $currentUser;

    /**
     * {@inheritdoc}
     */
    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition
    ) {
        // Refactor to use parent's $instance instead of our own __constructor
        // Required for Webform 6.x > || Drupal 9.x
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->entityTypeManager = $container->get('entity_type.manager');
        $instance->tokenManager = $container->get('webform.token_manager');
        $instance->fileSystem = $container->get('file_system');
        // Soft depend on "file" module so this service might not be available.
        $instance->fileUsage = $container->get('file.usage');
        $instance->transliteration = $container->get('transliteration');
        $instance->languageManager = $container->get('language_manager');
        $instance->webformElementManager = $container->get('plugin.manager.webform.element');
        $instance->entityTypeBundleInfo = $container->get('entity_type.bundle.info');
        $instance->strawberryfieldUtility = $container->get('strawberryfield.utility');
        $instance->entityFieldManager = $container->get('entity_field.manager');
        $instance->currentUser = $container->get('current_user');
        return $instance;
    }

    /**
     * @return bool
     */
    public function isWidgetDriven(): bool {
        return $this->isWidgetDriven;
    }

    /**
     * @param bool $isWidgetDriven
     */
    public function setIsWidgetDriven(bool $isWidgetDriven): void {
        $this->isWidgetDriven = $isWidgetDriven;
    }


    /**
     * {@inheritdoc}
     */
    public function postLoad(WebformSubmissionInterface $webform_submission) {
        parent::postLoad(
            $webform_submission
        ); // TODO: Change the autogenerated stub

    }



    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration() {
        // @TODO this will be sent to Esmero.
        return [
            'submission_url' => 'https://api.example.org/SOME/ENDPOINT',
            'upload_scheme' => 'public://',
            'operation' => NULL,
            'ado_settings' => [],
            'ado_crud_enabled' => FALSE,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSummary() {
        $configuration = $this->getConfiguration();
        $settings = $configuration['settings'];
        unset($settings['states']);
        unset($settings['ado_settings']);

        return [
                '#theme' => 'webform_handler_strawberryfieldharvester_summary',
                '#settings' => $settings,
                '#handler' => $this,
            ] + parent::getSummary();
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(
        array $form,
        FormStateInterface $form_state
    ) {
        $form['submission_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Secondary submission URL to api.example.org'),
            '#description' => $this->t('The URL to post the submission data to.'),
            '#default_value' => $this->configuration['submission_url'],
            '#required' => TRUE,
        ];
        $scheme_options = OcflHelper::getVisibleStreamWrappers();
        $form['upload_scheme'] = [
            '#type' => 'radios',
            '#title' => $this->t('Permanent destination for uploaded files'),
            '#description' => $this->t(
                'The Permanent URI Scheme destination for uploaded files.'
            ),
            '#default_value' => $this->configuration['upload_scheme'],
            '#required' => TRUE,
            '#options' => $scheme_options,
        ];
        // Get the webform elements options array.
        $webform_elements = $this->getElements(TRUE);
        $this->applyFormStateToConfiguration($form_state);
        // Additional settings for Self Ingest as Entity
        // Entity settings.
        $form['ado_settings'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('ADO Entity manipulation settings'),
            '#collapsible' => FALSE,
            '#attributes' => ['id' => 'webform-handler-ajax-ado-enabled'],
        ];


        // Define #ajax callback.
        $ajax_ado_enabled = [
            'callback' => [get_class($this), 'ajaxCallbackEntity'],
            'wrapper' => 'webform-handler-ajax-ado-enabled',
        ];

        $form['ado_crud_enabled'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Allow this handler to manipulate ADOs outside of an Node update/create operation'),
            '#default_value' => $this->configuration['ado_crud_enabled'],
            '#ajax' => $ajax_ado_enabled,
            '#parents' => [
                'settings',
                'ado_crud_enabled',
            ],
        ];

        // Define #ajax callback.
        $ajax = [
            'callback' => [get_class($this), 'ajaxCallbackEntityConfig'],
            'wrapper' => 'webform-handler-ajax-container',
        ];

        $form['ado_settings']['container']['operation'] = [
            '#type' => 'webform_select_other',
            '#title' => $this->t('Entity operation'),
            '#description' => $this->t('If the entity ID is empty a new entity will be created an then updated with the new entity ID.'),
            '#options' => [
                '_default' => $this->t('Create a new entity'),
                $this->t('or update entity ID stored in the following submission element:')->__toString() => $webform_elements,
                \Drupal\webform\Element\WebformOtherBase::OTHER_OPTION => $this->t('Update custom entity ID…'),
            ],
            '#empty_option' => $this->t('- Select -'),
            '#required' => TRUE,
            '#default_value' => $this->configuration['operation'],
            '#parents' => [
                'settings',
                'ado_settings',
                'operation',
            ],
        ];



        $bundle_options = [];
        //Only node bundles with a strawberry field are allowed
        // @TODO allow in the future other entities, not only nodes

        /**************************************************************************/
        // Node types with Strawberry fields
        /**************************************************************************/

        $bundles = $this->entityTypeBundleInfo->getBundleInfo('node');
        foreach ($bundles as $bundle => $bundle_info) {
            if ($this->strawberryfieldUtility->bundleHasStrawberryfield($bundle)) {
                $bundle_options[$bundle] = $bundle_info['label'];
            }
        }

        $form['ado_settings']['container'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'webform-handler-ajax-container'],
            '#access' => $this->configuration['ado_crud_enabled']? TRUE: FALSE,

        ];

        $current_bundle = isset($this->configuration['ado_settings']['bundles']) ? $this->configuration['ado_settings']['bundles'] : NULL;
        $form['ado_settings']['container']['bundles'] = [
            '#title' => $this->t('Node types this webform handler can manipulate'),
            '#type' => 'radios',
            '#options' => $bundle_options,
            '#default_value' => $current_bundle,
            '#required'=> true,
            '#ajax' => $ajax,
            '#access' => $bundle_options ? TRUE : FALSE,
            '#parents' => [
                'settings',
                'ado_settings',
                'bundles',
            ],

        ];

        //@see \Drupal\content_moderation\Form\ContentModerationConfigureForm::buildConfigurationForm
        // as alternative option for this

        /**************************************************************************/
        // Fields.
        /**************************************************************************/

        // Get elements options.
        $element_options = [];
        $elements = $this->webform->getElementsInitializedFlattenedAndHasValue();
        foreach ($elements as $element_key => $element) {
            $element_options[$element_key] = (isset($element['#title'])) ? $element['#title'] : $element_key;
        }

        // Use the passed bundles in case we are playing around

        $selected_bundle = $form_state->getValue(['ado_settings','bundles']) ? $form_state->getValue(['ado_settings','bundles']) : $current_bundle;

        if ($selected_bundle) {
            $field_options = $this->strawberryfieldUtility->getStrawberryfieldMachineForBundle($selected_bundle);
            $sbf_options = [];
            foreach($field_options as $field_names) {
                $sbf_options[$field_names] = $field_names;
            }
            $default_sbf_option = reset($sbf_options);
            $form['ado_settings']['container'][$bundle]['bundled_fields'] = $this->getFieldsForBundle($selected_bundle);
            $form['ado_settings']['container'][$bundle]['bundled_fields']['#parents'] = ['settings','ado_settings', 'bundled_fields'];

            $form['ado_settings']['container'][$bundle]['sbf_fields'] = [
                '#type' => 'webform_mapping',
                '#title' => $this->t('Webform Values that are going to be ingested as JSON for a @bundle', ['@bundle' => $bundle]) ,
                '#description' => $this->t(
                    'Please select which SBF field webform submission data should be mapped to'
                ),
                '#description_display' => 'before',
                '#empty_option' => $this->t('- Do Not Map -'),
                '#default_value' => isset($this->configuration['ado_settings']['sbf_fields']) ? $this->configuration['ado_settings']['sbf_fields'] : [],
                '#required' => true,

                '#parents' => ['settings','ado_settings', 'sbf_fields'],
                '#source' => $element_options,
                '#destination' => $sbf_options,
            ];
        }
        // This are the minimal things we need
        /* $node = Node::create(array(
             'type' => 'your_content_type', WE GOT THIS
             'title' => 'your title', WE WILL ALWAYS USE LABEL!
             'langcode' => 'en', // SHOULD BE BY DEFAULT?
             'uid' => '1', // The ID of the USER?
             'status' => 1, // Draft? 0 // Published 1
             'field_fields' => array(),
         ));*/



        $form['ado_settings']['container']['entity_revision'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Create new revision'),
            '#default_value' => $this->configuration['ado_settings']['entity_revision'],
            '#access' => FALSE,
            '#parents' => [
                'settings',
                'ado_settings',
                'entity_revision',
            ],
        ];
        if ($selected_bundle) {

            $form['ado_settings']['container']['entity_revision']['#access'] = $this->entityTypeManager->getDefinition('node')->isRevisionable();
        }

        $form['token_tree_link'] = $this->tokenManager->buildTreeLink();

        // Check if the form has disabled saving of results
        $results_disabled = $this->getWebform()->getSetting('results_disabled');

        $form['ado_settings']['container']['triggering_states'] = [
            '#type' => 'checkboxes',
            '#title' => $this->t('When to execute the ingest'),
            '#options' => [
                WebformSubmissionInterface::STATE_DRAFT => $this->t('…when <b>draft</b> is saved.'),
                WebformSubmissionInterface::STATE_CONVERTED => $this->t('…when anonymous submission is <b>converted</b> to authenticated.'),
                WebformSubmissionInterface::STATE_COMPLETED => $this->t('…when submission is <b>completed</b>.'),
                WebformSubmissionInterface::STATE_UPDATED => $this->t('…when submission is <b>updated</b>.'),
                WebformSubmissionInterface::STATE_DELETED => $this->t('…when submission is <b>deleted</b>.'),
            ],
            '#parents' => [
                'settings',
                'states',
            ],
            '#access' => $results_disabled ? FALSE : TRUE,
            '#default_value' => $results_disabled ? [WebformSubmissionInterface::STATE_COMPLETED] : $this->configuration['states'],
        ];

        return $form;
    }


    /**
     * Ajax callback.
     *
     * @param array $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     *
     * @return array
     *   An associative array containing entity reference details element.
     */
    public static function ajaxCallbackEntityConfig(array $form, FormStateInterface $form_state) {
        $to_return = NestedArray::getValue($form, ['settings', 'ado_settings','container']);
        return is_array($to_return) ? $to_return : [];
    }

    /**
     * Enabled/Disabled Ajax callback.
     *
     * @param array $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     *
     * @return array
     *   An associative array containing entity reference details element.
     */
    public static function ajaxCallbackEntity(array $form, FormStateInterface $form_state) {
        dpm($form);
        $to_return = NestedArray::getValue($form, ['settings', 'ado_settings']);
        dpm($to_return);
        return is_array($to_return) ? $to_return : [];
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     */
    public function submitConfigurationForm(
        array &$form,
        FormStateInterface $form_state
    )
    {
        parent::submitConfigurationForm($form, $form_state);
        error_log(var_export($form_state->getValues(), true));
        $this->applyFormStateToConfiguration($form_state);

        // Cleanup states.
        $this->configuration['states'] = array_values(array_filter($this->configuration['states']));

        // Cleanup entity values.
        // $this->configuration['ado_settings'] = array_map('array_filter', $this->configuration['ado_settings']);
        // $this->configuration['ado_settings'] = array_filter($this->configuration['ado_settings']);
        error_log('well it was submitted');
        error_log(var_export($this->configuration, true));
    }

    /**
     * {@inheritdoc}
     */
    public function preSave(WebformSubmissionInterface $webform_submission) {

        $values = $webform_submission->getData();
        $cleanvalues = $values;
        $processedcleanvalues = [];
        // Helper structure to keep elements that map to entities around
        $entity_mapping_structure = isset($cleanvalues['ap:entitymapping']) ? $cleanvalues['ap:entitymapping'] : [];
        // Check which elements carry files around
        $allelements = $webform_submission->getWebform()->getElementsManagedFiles();
        foreach ($allelements as $element) {
            $originalelement = $webform_submission->getWebform()->getElement(
                $element
            );
            // Track what fields map to file entities.
            $entity_mapping_structure['entity:file'][] = $originalelement['#webform_key'];
            // Process each managed files field.
            $processedcleanvaluesforfield = $this->processFileField(
                $originalelement,
                $webform_submission,
                $cleanvalues
            );
            // Merge since different fields can contribute to same as:filetype structure.
            $processedcleanvalues = array_merge_recursive(
                $processedcleanvalues,
                $processedcleanvaluesforfield
            );
        }
        // Check also which elements carry entity references around
        // @see https://www.drupal.org/project/webform/issues/3067958
        if (isset($entity_mapping_structure['entity:node'])) {
            //@TODO change this stub. Get every element that extends Drupal\webform\Plugin\WebformElementEntityReferenceInterface()
            $entity_mapping_structure['entity:node'] = array_values(
                array_unique($entity_mapping_structure['entity:node'],
                    SORT_STRING
                ));
        }

        if (isset($entity_mapping_structure['entity:file'])) {
            $entity_mapping_structure['entity:file'] = array_values(
                array_unique($entity_mapping_structure['entity:file'],
                    SORT_STRING
                ));
        }
        // Distribute all processed AS values for each field into its final JSON
        // Structure, e.g as:image, as:application, as:documents, etc.
        foreach ($processedcleanvalues as $askey => $info) {
            //@TODO ensure non managed files inside structure are preserved.
            //Could come from another URL only field or added manually by some
            // Advanced user.
            $cleanvalues[$askey] = $info;
        }
        $cleanvalues['ap:entitymapping'] = $entity_mapping_structure;

        if (isset($values["strawberry_field_widget_state_id"])) {

            $this->setIsWidgetDriven(TRUE);
            /* @var $tempstore \Drupal\Core\TempStore\PrivateTempStore */
            $tempstore = \Drupal::service('tempstore.private')->get('archipel');

            unset($cleanvalues["strawberry_field_widget_state_id"]);
            unset($cleanvalues["strawberry_field_stored_values"]);

            // That way we keep track who/what created this.
            $cleanvalues["strawberry_field_widget_id"] = $this->getWebform()->id();
            // Set data back to the Webform submission so we don't keep track
            // of the strawberry_field_widget_state_id if the submission is also saved
            $webform_submission->setData($cleanvalues);

            $cleanvalues = json_encode($cleanvalues, JSON_PRETTY_PRINT);

            try {
                $tempstore->set(
                    $values["strawberry_field_widget_state_id"],
                    $cleanvalues
                );
            } catch (TempStoreException $e) {
                $this->messenger()->addError(
                    $this->t(
                        'Sorry, we have issues writing metadata to your session storage. Please reload this form and/or contact your system admin.'
                    )
                );
                $this->loggerFactory->get('archipelago')->error(
                    'Webform @webformid can not write to temp storage! with error @message. Attempted Metadata input was <pre><code>%data</code></pre>',
                    [
                        '@webformid' => $this->getWebform()->id(),
                        '%data' => print_r($webform_submission->getData(), TRUE),
                        '@error' => $e->getMessage(),
                    ]
                );
            }


        }
        elseif ($this->IsWidgetDriven()) {
            $this->messenger()->addError(
                $this->t(
                    'We lost TV reception in the middle of the match...Please contact your system admin.'
                )
            );
            $this->loggerFactory->get('archipelago')->error(
                'Webform @webformid lost connection to temp storage and its Widget!. No Widget State id present. Attempted Metadata input was <pre><code>%data</code></pre>',
                [
                    '@webformid' => $this->getWebform()->id(),
                    '%data' => print_r($webform_submission->getData(), TRUE),
                ]
            );
        }

        parent::preSave($webform_submission); // TODO: Change the autogenerated stub
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(
        array &$form,
        FormStateInterface $form_state,
        WebformSubmissionInterface $webform_submission
    ) {
        $values = $webform_submission->getData();

        if ((!isset($values["strawberry_field_widget_state_id"]) || empty($values["strawberry_field_widget_state_id"])) && $this->IsWidgetDriven(
            )) {
            $this->messenger()->addError(
                $this->t(
                    'Sorry, we have issues reading your session storage identifier. Error was logged. Please reload this form and/or contact your system admin.'
                )
            );

            $this->loggerFactory->get('archipelago')->error(
                'Webform @webformid lost connection to temp storage!. No Widget State id present. Attempted Metadata input was <pre><code>%data</code></pre>',
                [
                    '@webformid' => $this->getWebform()->id(),
                    '%data' => print_r($webform_submission->getData(), TRUE),
                ]
            );
        }
        // All data is available here $webform_submission->getData()));
        // @TODO what should be validated here?
        parent::validateForm(
            $form,
            $form_state,
            $webform_submission
        ); // TODO: Change the autogenerated stub
    }


    /**
     * {@inheritdoc}
     */
    public function submitForm(
        array &$form,
        FormStateInterface $form_state,
        WebformSubmissionInterface $webform_submission
    ) {
        $values = $webform_submission->getData();

        if (isset($values["strawberry_field_widget_state_id"])) {
            $this->setIsWidgetDriven(TRUE);
        }
        // @TODO add a full-blown values cleaner
        // @TODO add the webform name used to create this as additional KEY
        // @TODO make sure widget can read that too.
        // @If Widget != setup form, ask for User feedback
        // @TODO, i need to alter node submit handler to add also the
        // Entities full URL as an @id to the top of the saved JSON.
        // FUN!
        // Get the URL to post the data to.
        // @todo esmero a.k.a as Fedora-mockingbird
        //$post_url = $this->configuration['submission_url'];
    }

    /**
     * Process temp files and give them SBF structure
     *
     * @param array $element
     *   An associative array containing the file webform element.
     * @param \Drupal\webform\webformSubmissionInterface $webform_submission
     * @param $cleanvalues
     *
     * @return array
     *   Associative array keyed by AS type with binary info.
     */
    public function processFileField(
        array $element,
        WebformSubmissionInterface $webform_submission,
        $cleanvalues
    ) {

        $key = $element['#webform_key'];
        $type = $element['#type'];
        // Equivalent of getting original data from an node entity
        $original_data = $webform_submission->getOriginalData();
        $processedAsValues = [];

        $value = isset($cleanvalues[$key]) ? $cleanvalues[$key] : [];
        $fids = (is_array($value)) ? $value : [$value];

        $original_value = isset($original_data[$key]) ? $original_data[$key] : [];
        $original_fids = (is_array(
            $original_value
        )) ? $original_value : [$original_value];

        // Delete the old file uploads?
        // @TODO build some cleanup logic here. Could be moved to attached field hook.
        // Issue with this approach is that it is 100% webform dependant
        // Won't apply in the same way if using direct JSON input and node save.

        $delete_fids = array_diff($original_fids, $fids);

        // @TODO what do we do with removed files?
        // Idea. Check the fileUsage. If there is still some other than this one
        // don't remove.
        // @see \Drupal\webform\Plugin\WebformElement\WebformManagedFileBase::deleteFiles

        // Exit if there is no fids.
        if (empty($fids)) {
            return $processedAsValues;
        }

        /* @see \Drupal\strawberryfield\StrawberryfieldFilePersisterService */
        $processedAsValues = \Drupal::service('strawberryfield.file_persister')
            ->generateAsFileStructure($fids, $key, $cleanvalues);
        return $processedAsValues;

    }

    /**
     * {@inheritdoc}
     */
    public function confirmForm(
        array &$form,
        FormStateInterface $form_state,
        WebformSubmissionInterface $webform_submission
    ) {
        // We really want to avoid being redirected. This is how it is done.
        //@TODO manage file upload if there is no submission save handler
        //@ see \Drupal\webform\Plugin\WebformElement\WebformManagedFileBase::postSave

        $form_state->disableRedirect();
    }

    /**
     * {@inheritdoc}
     */
    public function preprocessConfirmation(array &$variables) {
        if ($this->isWidgetDriven()) {
            unset($variables['back']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function overrideSettings(
        array &$settings,
        WebformSubmissionInterface $webform_submission
    ) {
        // We can not check if they are already overridden
        // Because this is acting as an alter
        // But never ever touches the Webform settings.
        $settings = $this->customWebformSettings + $settings;

        parent::overrideSettings(
            $settings,
            $webform_submission
        );
    }

    /**
     * {@inheritdoc}
     */
    public function preCreate(array &$values) {
        if (isset($values['strawberryfield:override']) && !empty($values['strawberryfield:override']) && empty($this->customWebformSettings)) {
            $this->customWebformSettings = $values['strawberryfield:override'];
        }
        parent::preCreate($values);
    }

    /**
     * @param webformSubmissionInterface $webform_submission
     */
    public function postCreate(WebformSubmissionInterface $webform_submission) {
        //using our custom original data array to set setOriginalData;
        $data = $this->webformSubmission->getData();
        if (empty($this->webformSubmission->getOriginalData()) &&
            isset($data['strawberry_field_stored_values'])) {
            $this->webformSubmission->setOriginalData($data['strawberry_field_stored_values']);
        }
        parent::postCreate(
            $webform_submission
        ); // TODO: Change the autogenerated stub
    }


    /**
     * Gets valid upload stream wrapper schemes.
     *
     * @param array $element
     *
     * @return mixed|string
     */
    protected function getUriSchemeForManagedFile(array $element) {
        if (isset($element['#uri_scheme'])) {
            return $element['#uri_scheme'];
        }
        $scheme_options = \Drupal\webform\Plugin\WebformElement\WebformManagedFileBase::getVisibleStreamWrappers(
        );
        if (isset($scheme_options['private'])) {
            return 'private';
        }
        elseif (isset($scheme_options['public'])) {
            return 'public';
        }
        else {
            return 'private';
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
        // Log the current page.
        $current_page = $webform_submission->getCurrentPage();
        $webform = $webform_submission->getWebform();
        // Get navigation webform settings.
        // Actions to perform if forward navigation is enabled and there are pages.
        if ($webform->hasWizardPages()) {
            $validations = [
                '::validateForm',
                '::draft',
            ];
            // Allow forward access to all but the confirmation page.
            foreach ($form_state->get('pages') as $page_key => $page) {
                // Allow user to access all but the confirmation page.
                if ($page_key != 'webform_confirmation') {
                    $form['pages'][$page_key]['#access'] = TRUE;
                    $form['pages'][$page_key]['#validate'] = $validations;
                }
            }
            // Set our loggers to the draft update if it is set.
            if (isset($form['actions']['draft'])) {
                // Add a logger to the next validators.
                $form['actions']['draft']['#validate'] = $validations;
            }
            // Set our loggers to the previous update if it is set.
            if (isset($form['actions']['wizard_prev'])) {
                // Add a logger to the next validators.
                $form['actions']['wizard_prev']['#validate'] = $validations;
            }
            // Add a custom validator to the final submit.
            //$form['actions']['submit']['#validate'][] = 'webformnavigation_submission_validation';
            // Log the page visit.
            // $visited = $this->webformNavigationHelper->hasVisitedPage($webform_submission, $current_page);
            // Log the page if it has not been visited before.
            //if (!$visited) {
            // $this->webformNavigationHelper->logPageVisit($webform_submission, $current_page);
            // }
            elseif ($current_page != 'webform_confirmation') {
                // Display any errors.
            }
        }
    }

    /**
     * Webform submission handler to autosave when there are validation errors.
     *
     * @param array $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     */
    public function autosave(array &$form, FormStateInterface $form_state) {
        if ($form_state->hasAnyErrors()) {
            if ($this->draftEnabled() && $this->getWebformSetting('draft_auto_save') && !$this->entity->isCompleted()) {
                $form_state->set('in_draft', TRUE);

                $this->submitForm($form, $form_state);
                //$this->save($form, $form_state);
                $this->rebuild($form, $form_state);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function publishAdo(WebformSubmissionInterface $webform_submission) {

        $current_langcode = $this->languageManager->getCurrentLanguage()->getId();
        dpm('Submitted!');
        dpm($webform_submission->getData());

    }

    /**
     * Webform submission handler for the 'draft' action.
     *
     * @param array $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     */
    public function draft(array &$form, FormStateInterface $form_state) {
        $form_state->clearErrors();
        $form_state->set('in_draft', TRUE);
        $form_state->set('draft_saved', TRUE);
        $this->entity->validate();
    }

    /**
     * Helper method to get all elements of this webform.
     *
     * @param bool $composite
     *      If false removes compounded values we know won't be able to go into a normal Node Entity base field.
     * @return array
     *   Array of webform elements to be used as select options.
     */
    protected function getElements($composite = TRUE) {
        $which = $composite ? 'composite' : 'single';
        $elements_options = &drupal_static(__FUNCTION__,[]);

        if (!isset($elements_options[$which])) {
            $elements_options[$which] = [];
            foreach ($this->getWebform()->getElementsDecodedAndFlattened() as $key => $element) {
                try {
                    /* @var \Drupal\webform\Plugin\WebformElementInterface $element_handler */
                    $element_handler = $this->webformElementManager->createInstance($element['#type']);
                    // Discards $composites.
                    // Default behaviour for Base Field to value mapping.
                    if (!$composite && $element_handler->isComposite()) {
                        continue;
                    }
                    if ($element_handler->isInput($element)) {
                        $title = empty($element['#title']) ? $key : $element['#title'] . " ($key)";
                        $elements_options[$which]['input:' . $key] = $title;
                    }
                }
                catch (\Exception $exception) {
                    // Nothing to do for now.
                    // Should we alert the user? What to alert?
                }
            }
        }

        return $elements_options[$which];
    }

    /**
     * Get all Fields for the selected bundle.
     *
     * @param string $bundle
     *   The Bundle / Content Type
     *
     * @return array
     *   The composed form with the entity type fields.
     */
    protected function getFieldsForBundle($bundle) {
        $form = [];

        /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $properties */
        $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
        foreach ($fields as $field_name => $field) {

            // Skip all read only or computed or SBF since the later have their own mapping
            if ($field->isComputed() || $field->isReadOnly() || $field->getType() == 'strawberryfield_field') {
                continue;
            }
            // The $base_field here is an override. Imagine like a recreation of the original instance.
            $base_field = BaseFieldDefinition::create($field->getType());

            $field_properties = method_exists($field, 'getPropertyDefinitions') ? $field->getPropertyDefinitions() : $base_field->getPropertyDefinitions();

            if (empty($field_properties)) {
                $field_properties = $base_field->getPropertyDefinitions();
            }
            $field_schema = method_exists($field, 'getSchema') ? $field->getSchema() : $base_field->getSchema();

            // Use only properties with schema.
            if (!empty($field_schema['columns'])) {
                $field_properties = array_intersect_key($field_properties, $field_schema['columns']);
            }


            if (!empty($field_properties)) {
                $form[$field_name] = [
                    '#type' => 'details',
                    '#title' => $this->t('@label (Property: @name - Type: @type)', [
                        '@label' => $field->getLabel(),
                        '@name' => $field_name,
                        '@type' => $field->getType(),
                    ]),
                    '#description' => $field->getDescription(),
                    '#open' => FALSE,
                    '#required' => $field->isRequired(),
                    // @TODO Validate if any child has value.
                ];

                foreach ($field_properties as $property_name => $property) {
                    $form[$field_name][$property_name] = [
                        '#type' => 'webform_select_other',
                        '#title' => $this->t('Column: @name - Type: @type', [
                            '@name' => $property->getLabel(),
                            '@type' => $property->getDataType(),
                        ]),
                        '#description' => $property->getDescription(),
                        '#options' => ['_null_' => $this->t('Null')] + $this->getElements(FALSE) + [WebformOtherBase::OTHER_OPTION => $this->t('Custom value…')],
                        '#default_value' => $this->configuration['ado_settings']['bundled_fields'][$field_name][$property_name] ?? NULL,
                        '#empty_value' => NULL,
                        '#empty_option' => $this->t('- Select -'),
                        '#parents' => [
                            'settings',
                            'ado_settings',
                            'bundled_fields',
                            $field_name,
                            $property_name,
                        ],
                        // @TODO Use the property type.
                        '#other__type' => 'textfield',
                    ];
                }
            }
        }

        // Remove the entity ID and bundle, they have theirs own settings.
        try {
            $entity_id_key = $this->entityTypeManager->getDefinition('node')->getKey('id');
            unset($form[$entity_id_key]);

            if ($this->entityTypeManager->getDefinition('node')->hasKey('bundle')) {
                unset($form[$this->entityTypeManager->getDefinition('node')->getKey('bundle')]);
            }
        }
        catch (\Exception $exception) {
            // Nothing to do.
        }
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE)
    {
        if ($this->isWidgetDriven() && $this->currentUser->isAnonymous()) {
            // WE do not allow Anonymous Users to Create ADOs at all.
            return;
            // Do not act here. Widget Driven means we are ingesting using NODE/Entity form SBF widget
        } else {
            // Ok here is where it gets cool
            // Let's start with checking if current user can even create a node of type bundle
            $bundle = $this->configuration['ado_settings']['bundles'];
            $type = 'node'; // ONly creating ADOS based on nodes for now.

            $access = $this->entityTypeManager
                ->getAccessControlHandler($type)
                ->createAccess($bundle, $this->currentUser, [], true);
            if ($access->isForbidden() || $access->isNeutral()) {
                return;
            }

            $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
            if (in_array($state, $this->configuration['states'])) {
                // Get the handler configuration and replace the values of the mapped
                // elements.
                $data = $this->configuration['ado_settings']['bundled_fields'];
                $data = array_map('array_filter',$data);
                $data = array_filter($data);
                dpm($data);
                array_walk_recursive($data, function (&$value) use ($webform_submission) {
                    if (strpos($value, 'input:') !== FALSE) {
                        list(, $element_key) = explode(':', $value);
                        $value = $webform_submission->getElementData($element_key);
                    } elseif ($value === '_null_') {
                        $value = NULL;
                    }

                    // Replace its token values.
                    $value = $this->tokenManager->replace($value, $webform_submission);
                });

                $data[$this->entityTypeManager->getDefinition($type)->getKey('bundle')] = $bundle;

                // Now add the SBF data, if any mapped
                $values = $webform_submission->getData();
                $mappings = array_filter($this->configuration['ado_settings']['sbf_fields']);
                if (count($mappings)) {
                    dpm($mappings);
                    $mappings = array_flip($mappings);
                    foreach($mappings as $sbf_field => $list_of_webform_keys) {
                        dpm($mappings);
                        dpm($sbf_field);
                        $jsonarray = $this->calculateSBFJSON($values, $webform_submission);
                        $jsonvalue =  json_encode($jsonarray, JSON_PRETTY_PRINT);
                        $data[$sbf_field] = $jsonvalue;
                    }
                }

                try {
                    $entity_id = FALSE;
                    if ($this->configuration['operation'] != '_default') {
                        if (strpos($this->configuration['operation'], 'input:') !== FALSE) {
                            list(, $element_key) = explode(':', $this->configuration['operation']);
                            $entity_id = $webform_submission->getElementData($element_key);
                        } else {
                            $entity_id = $this->configuration['operation'];
                        }
                        $entity_id = $this->tokenManager->replace($entity_id, $webform_submission);
                    }

                    if (!empty($entity_id)) {
                        // Load the entity and update the values.
                        if (($entity = $this->entityTypeManager->getStorage($type)->load($entity_id)) !== NULL) {
                            foreach ($data as $field => $value) {
                                $entity->set($field, $value);
                            }
                            // Now add the SBF data, if any mapped
                            $values = $webform_submission->getData();
                            $mappings = array_filter($this->configuration['ado_settings']['sbf_fields']);
                            if (count($mappings)) {
                                dpm($mappings);
                                $mappings = array_flip($mappings);
                                foreach($mappings as $sbf_field => $list_of_webform_keys) {
                                    dpm($mappings);
                                    dpm($sbf_field);
                                    $jsonarray = $this->calculateSBFJSON($values, $webform_submission);
                                    $jsonvalue =  json_encode($jsonarray, JSON_PRETTY_PRINT);
                                    $entity->set($sbf_field, $jsonvalue);
                                }
                            }
                        }
                    }

                    if (empty($entity)) {
                        // Create the entity with the values.
                        $entity = $this->entityTypeManager->getStorage($type)->create($data);
                    }

                    if ($this->entityTypeManager->getDefinition($type)->isRevisionable()) {
                        $entity->setNewRevision($this->configuration['ado_settings']['entity_revision']);
                    }

                    if ($entity->save() == SAVED_NEW) {
                        $message = '@type %title has been created.';
                    } else {
                        $message = '@type %title has been updated.';
                    }

                    $context = [
                        '@type' => $entity->getEntityType()->getLabel(),
                        '%title' => $entity->label(),
                    ];
                    if ($entity->hasLinkTemplate('canonical')) {
                        $context += [
                            'link' => $entity->toLink($this->t('View'))->toString(),
                        ];
                    }

                    if ($webform_submission->getWebform()->hasSubmissionLog()) {
                        // Log detailed message to the 'webform_submission' log.
                        $context += [
                            'link' => ($webform_submission->id()) ? $webform_submission->toLink($this->t('View'))->toString() : NULL,
                            'webform_submission' => $webform_submission,
                            'handler_id' => $this->getHandlerId(),
                            'operation' => 'sent email',
                        ];
                        $this->getLogger('webform_submission')->notice($message, $context);
                    } else {
                        // Log general message to the 'webform_entity_handler' log.
                        $context += [
                            'link' => $this->getWebform()->toLink($this->t('Edit'), 'handlers')->toString(),
                        ];
                        $this->getLogger('webform_entity_handler')->notice($message, $context);
                    }

                    // Update the entity ID.
                    if ($entity &&
                        $this->configuration['operation'] != '_default' &&
                        strpos($this->configuration['operation'], 'input:') !== FALSE) {

                        list(, $element_key) = explode(':', $this->configuration['operation']);
                        $webform_submission->setElementData($element_key, $entity->id());
                        $webform_submission->resave();
                    }
                } catch (\Exception $exception) {
                    watchdog_exception('webform_entity_handler', $exception);
                    $this->messenger()->addError($this->t('There was a problem processing your request. Please check the logs'));
                }
            }
        }
    }

    /**
     * Calculates the JSON payload that needs to be pushed into the SBF
     *
     * @param array $values
     * @param webformSubmissionInterface $webform_submission
     * @return array
     * @throws \Exception
     */
    public function calculateSbfJson(array $values, WebformSubmissionInterface $webform_submission) {

        $cleanvalues = $values;
        $processedcleanvalues = [];
        // Helper structure to keep elements that map to entities around
        $entity_mapping_structure = isset($cleanvalues['ap:entitymapping']) ? $cleanvalues['ap:entitymapping'] : [];
        // Check which elements carry files around
        $allelements = $webform_submission->getWebform()->getElementsManagedFiles();
        $anyelement = $webform_submission->getWebform()->getElementsInitializedAndFlattened();
        foreach ($anyelement as $element) {
            $element_plugin = $this->webformElementManager->getElementInstance($element);
            if ($element_plugin instanceof WebformElementEntityReferenceInterface && !($element_plugin instanceof WebformManagedFileBase)) {
                $original_entity_reference_element = $webform_submission->getWebform()->getElement(
                    $element
                );
                $entity_mapping_structure['entity:node'][] = $original_entity_reference_element['#webform_key'];
            }

        }

        foreach ($allelements as $element) {
            $originalelement = $webform_submission->getWebform()->getElement(
                $element
            );
            // Track what fields map to file entities.
            $entity_mapping_structure['entity:file'][] = $originalelement['#webform_key'];
            // Process each managed files field.
            $processedcleanvaluesforfield = $this->processFileField(
                $originalelement,
                $webform_submission,
                $cleanvalues
            );
            // Merge since different fields can contribute to same as:filetype structure.
            $processedcleanvalues = array_merge_recursive(
                $processedcleanvalues,
                $processedcleanvaluesforfield
            );
        }
        // Check also which elements carry entity references around
        // @see https://www.drupal.org/project/webform/issues/3067958
        if (isset($entity_mapping_structure['entity:node'])) {
            //@TODO change this stub. Get every element that extends Drupal\webform\Plugin\WebformElementEntityReferenceInterface()
            $entity_mapping_structure['entity:node'] = array_values(
                array_unique($entity_mapping_structure['entity:node'],
                    SORT_STRING
                ));
        }

        if (isset($entity_mapping_structure['entity:file'])) {
            $entity_mapping_structure['entity:file'] = array_values(
                array_unique($entity_mapping_structure['entity:file'],
                    SORT_STRING
                ));
        }
        // Distribute all processed AS values for each field into its final JSON
        // Structure, e.g as:image, as:application, as:documents, etc.
        foreach ($processedcleanvalues as $askey => $info) {
            //@TODO ensure non managed files inside structure are preserved.
            //Could come from another URL only field or added manually by some
            // Advanced user.
            $cleanvalues[$askey] = $info;
        }
        $cleanvalues['ap:entitymapping'] = $entity_mapping_structure;
        $cleanvalues["strawberry_field_widget_id"] = $this->getWebform()->id();
        return $cleanvalues;
    }
}
