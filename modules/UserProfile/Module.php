<?php declare(strict_types=1);

namespace UserProfile;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\Config\Reader\Ini as IniReader;
use Laminas\Config\Reader\Json as JsonReader;
use Laminas\Config\Reader\Xml as XmlReader;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Representation\UserRepresentation::class,
            'rep.resource.json',
            [$this, 'filterResourceJsonUser']
        );

        // Manage user settings via rest api (and the new user via ui).
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            // 'api.create.pre',
            'api.create.post',
            [$this, 'apiCreatePreUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.hydrate.pre',
            [$this, 'apiHydratePreUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.create.post',
            [$this, 'apiCreatePostUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.update.post',
            [$this, 'apiUpdatePostUser']
        );

        // Add the user profile to the user show admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.details',
            [$this, 'viewUserDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.show.after',
            [$this, 'viewUserShowAfter']
        );

        // Add the user profile elements to the user form.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'handleUserSettings']
        );
    }

    public function handleConfigForm(AbstractController $controller)
    {
        if (!parent::handleConfigForm($controller)) {
            return false;
        }

        $this->updateListFields();
        return true;
    }

    protected function updateListFields(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $fieldsConfig = $this->readConfigElements();

        $fields = [];
        $exclude = [
            'admin' => ['show' => [], 'edit' => []],
            'public' => ['show' => [], 'edit' => []],
        ];
        foreach ($fieldsConfig['elements'] as $element) {
            if (!isset($element['name'])) {
                continue;
            }
            $fields[$element['name']] = empty($element['options']['label'])
                ? $element['name']
                : $element['options']['label'];
            if (!empty($element['options']['exclude_admin_show'])) {
                $exclude['admin']['show'] = $element['name'];
            }
            if (!empty($element['options']['exclude_admin_edit'])) {
                $exclude['admin']['edit'] = $element['name'];
            }
            if (!empty($element['options']['exclude_public_show'])) {
                $exclude['public']['show'] = $element['name'];
            }
            if (!empty($element['options']['exclude_public_edit'])) {
                $exclude['public']['edit'] = $element['name'];
            }
        }

        $settings->set('userprofile_fields', $fields);
        $settings->set('userprofile_exclude', $exclude);
    }

    public function handleUserSettings(Event $event): void
    {
        // Compatibility with module Guest.
        $services = $this->getServiceLocator();
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if ($status->isSiteRequest()) {
            /** @var \Laminas\Router\Http\RouteMatch $routeMatch */
            $routeMatch = $services->get('Application')->getMvcEvent()->getRouteMatch();
            $controller = $routeMatch->getParam('controller');
            if ($controller === \Guest\Controller\Site\AnonymousController::class) {
                if ($routeMatch->getParam('action') !== 'register') {
                    return;
                }
            } elseif ($controller === \Guest\Controller\Site\GuestController::class) {
                $user = $services->get('Omeka\AuthenticationService')->getIdentity();
                $routeMatch->setParam('id', $user->getId());
            } else {
                return;
            }
            $this->handleAnySettings($event, 'user_settings');
        } elseif ($status->isApiRequest()) {
            $this->handleAnySettings($event, 'user_settings');
        } else {
            parent::handleUserSettings($event);
        }
    }

    protected function handleAnySettings(Event $event, $settingsType): ?\Laminas\Form\Fieldset
    {
        if ($settingsType !== 'user_settings') {
            return parent::handleAnySettings($event, $settingsType);
        }

        $elements = $this->readConfigElements();
        if (!$elements) {
            return null;
        }

        $form = $event->getTarget();
        $formFieldset = parent::handleAnySettings($event, $settingsType);

        // Specific to this module.
        $services = $this->getServiceLocator();

        /**
         * These settings can be managed in admin or via guest.
         * The user may be created or not yet.
         * In admin, the user may not be the current user.
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\Entity\User $user
         * @var \Omeka\Settings\UserSettings $userSettings
         */
        $status = $services->get('Omeka\Status');
        $isAdminRequest = $status->isAdminRequest();
        if ($isAdminRequest) {
            $userId = (int) $status->getRouteParam('id') ?: null;
        }
        if (empty($userId)) {
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            $userId = $user ? $user->getId() : null;
        }
        // Rights to edit user settings is already checked.
        if ($userId) {

            // In some cases (api), the user may not have been set.
            $userSettings = $services->get('Omeka\Settings\User');
            $userSettings->setTargetId($userId);

        } else {
            $userSettings = null;
        }

        $exclude = $this->excludedFields('edit');

        // In Omeka S < v4, the element groups are skipped.
        $elementGroups = [
            'profile' => 'Profile', // @translate
        ];

        foreach ($elements['elements'] as $name => $element) {
            if (in_array($name, $exclude)) {
                continue;
            }
            $data[$name] = $userSettings ? $userSettings->get($name) : null;
            if (empty($element['options']['element_group'])) {
                $element['options']['element_group'] = 'profile';
            } elseif (!isset($elementGroups[$element['options']['element_group']])) {
                // The key is checked in order to keep default group labels.
                $elementGroups[$element['options']['element_group']] = $element['options']['element_group'];
            }
            $formFieldset
                ->add($element);
            if ($userSettings) {
                $formFieldset
                    ->get($name)->setValue($data[$name]);
            }
        }

        $userSettingsFieldset = $form->get('user-settings');
        $userSettingsFieldset->setOption('element_groups', array_merge($userSettingsFieldset->getOption('element_groups') ?: [], $elementGroups));

        // Fix to manage empty values for selects and multicheckboxes.
        // @see \Omeka\Controller\SiteAdmin\IndexController::themeSettingsAction()
        $inputFilter = $form->getInputFilter()->get('user-settings');
        foreach ($formFieldset->getElements() as $element) {
            if ($element instanceof \Laminas\Form\Element\MultiCheckbox
                || $element instanceof \Laminas\Form\Element\Tel
                || ($element instanceof \Laminas\Form\Element\Select
                    && $element->getOption('empty_option') !== null)
            ) {
                $name = $element->getName();
                if (!in_array($name, $exclude)
                    && !$element->getAttribute('required')
                ) {
                    $inputFilter->add([
                        'name' => $name,
                        'allow_empty' => true,
                        'required' => false,
                    ]);
                }
            }
        }

        return $form;
    }

    public function filterResourceJsonUser(Event $event): void
    {
        $user = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');

        $services = $this->getServiceLocator();

        $settings = $services->get('Omeka\Settings');
        $elements = $settings->get('userprofile_elements', '');
        if (!$elements) {
            return;
        }

        $fields = $settings->get('userprofile_fields', []);
        if (!$fields) {
            return;
        }

        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());

        foreach (array_keys($fields) as $field) {
            $value = $userSettings->get($field);
            $jsonLd['o:setting'][$field] = $value;
        }

        $event->setParam('jsonLd', $jsonLd);
    }

    /**
     * Unlike update, create cannot manage appended fields in views currently.
     *
     * @param Event $event
     */
    public function apiCreatePreUser(Event $event): void
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if ($status->isApiRequest()) {
            return;
        }

        /** @var \Laminas\Http\PhpEnvironment\Request $request */
        $request = $services->get('Request');
        $post = $request->getPost();
        $userSettings = $post->offsetGet('user-settings') ?: [];
        $post->offsetSet('o:setting', $userSettings);
        $request->setPost($post);

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $request->setContent($post->toArray());

        // TODO Check request from admin and guest form.
        // $this->checkRequest($request);
    }

    public function apiHydratePreUser(Event $event): void
    {
        $services = $this->getServiceLocator();

        // Only for the rest api manager.
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            return;
        }

        $this->checkRequest($event);
    }

    protected function checkRequest(Event $event): void
    {
        // TODO Manage "required" during create and update.

        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings) {
            return;
        }

        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $errorStore = $event->getParam('errorStore');
        $user = $event->getParam('entity');
        $fieldset = $this->userSettingsFieldset($user ? $user->getId() : null);

        if (!is_array($requestUserSettings)) {
            $errorStore->addError('o:setting', new Message(
                'The key “o:setting” should be an array of user settings.' // @translate
            ));
            return;
        }

        foreach ($requestUserSettings as $key => $value) {
            if (!$fieldset->has($key)) {
                continue;
            }

            // TODO Use the validator of the element.
            /** @var \Laminas\Form\Element $element */
            $element = $fieldset->get($key);
            $isMultipleValues = is_array($value);

            if ($element->getAttribute('required')
                && (($isMultipleValues && !count($value)) || (!$isMultipleValues && !strlen($value)))
            ) {
                $errorStore->addError('o:setting', new Message(
                    'A value is required for “%s”.', // @translate
                    $key
                ));
                continue;
            }

            // Currently, only select and multicheckbox are checked.
            if (method_exists($element, 'getValueOptions')) {
                $valueOptions = $element->getValueOptions();
                // Note: value options can be an array of arrays with keys value
                // and label, iin particular when the config uses key with
                // forbidden letters.
                if (is_array(reset($valueOptions))) {
                    $result = [];
                    foreach ($valueOptions as $array) {
                        $result[$array['label']] = $array['value'];
                    }
                    $valueOptions = $result;
                }

                if ($isMultipleValues) {
                    $values = array_intersect_key($valueOptions, array_flip($value));
                    if (method_exists($element, 'isMultiple') && !$element->isMultiple()) {
                        $errorStore->addError('o:setting', new Message(
                            'Only one value is allowed for “%s”.', // @translate
                            $key
                        ));
                    } elseif (count($value) !== count($values)) {
                        $errorStore->addError('o:setting', new Message(
                            'One or more values (“%s”) are not allowed for “%s”.', // @translate
                            implode('”, “', array_diff($value, array_keys($valueOptions))), $key
                        ));
                    } elseif (!count($values) && $element->getAttribute('required')) {
                        $errorStore->addError('o:setting', new Message(
                            'A value is required for “%s”.', // @translate
                            $key
                        ));
                    }
                } else {
                    if (strlen($value) && !isset($valueOptions[$value])) {
                        $errorStore->addError('o:setting', new Message(
                            'The value “%s” is not allowed for “%s”.', // @translate
                            $value, $key
                        ));
                    }
                }
            } else {
                if (method_exists($element, 'isMultiple') && $element->isMultiple()
                    || $element instanceof \Laminas\Form\Element\MultiCheckbox
                ) {
                    $errorStore->addError('o:setting', new Message(
                        'An array of values is required for “%s”.', // @translate
                        $key
                    ));
                } elseif (!isset($valueOptions[$value])) {
                    $errorStore->addError('o:setting', new Message(
                        'The value “%s” is not allowed for “%s”.', // @translate
                        $value, $key
                    ));
                }
            }
            // TODO Add more check or use element validator.
        }
    }

    public function apiCreatePostUser(Event $event): void
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            /** @var \Laminas\Http\PhpEnvironment\Request $request */
            $request = $services->get('Request');
            $post = $request->getPost();
            $userSettings = $post->offsetGet('user-settings') ?: [];
            $post->offsetSet('o:setting', $userSettings);
            $request->setPost($post);

            /** @var \Omeka\Api\Request $request */
            $request = $event->getParam('request');
            $request->setContent($post->toArray());
        }
        $this->apiCreateOrUpdatePostUser($event);
    }

    /**
     * Unlike create, update is managed via the settings because it displays the
     * form a new time. So a specific check is done for update.
     *
     * @param Event $event
     */
    public function apiUpdatePostUser(Event $event): void
    {
        // Only for the rest api manager: in public or admin, the view is
        // reloaded and managed during the creation of the form.

        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            return;
        }
        $this->apiCreateOrUpdatePostUser($event);
    }

    protected function apiCreateOrUpdatePostUser(Event $event): void
    {
        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings || !is_array($requestUserSettings)) {
            return;
        }

        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $user = $event->getParam('response')->getContent();
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->getId());
        $fieldset = $this->userSettingsFieldset($user->getId());

        $exclude = $this->excludedFields('edit');

        foreach ($requestUserSettings as $key => $value) {
            // Silently skip if not exist for security and clean process.
            if (!$fieldset->has($key)) {
                continue;
            }

            // Skip elements for security: a user cannot edit an excluded field.
            if (in_array($key, $exclude)) {
                continue;
            }

            // TODO Use the validator of the element.
            /** @var \Laminas\Form\Element $element */
            $element = $fieldset->get($key);
            $isMultipleValues = is_array($value);

            // Some cleaning, required because some fields are not checked
            // during creation via form.
            if (method_exists($element, 'getValueOptions') && $isMultipleValues) {
                $value = array_keys(array_intersect_key($element->getValueOptions(), array_flip($value)));
            } elseif ($element instanceof \Laminas\Form\Element\Checkbox) {
                $value = (bool) $value;
            } elseif ($element instanceof \Laminas\Form\Element\Number) {
                $value = (int) $value;
            }

            $userSettings->set($key, $value);
        }
    }

    /**
     * Get the fieldset "user-setting" of the user form.
     *
     * @param int $userId
     * @return \Laminas\Form\Fieldset
     */
    protected function userSettingsFieldset($userId = null)
    {
        $form = $this->getServiceLocator()->get('FormElementManager')
            ->get(\Omeka\Form\UserForm::class, [
                'user_id' => $userId,
                'include_role' => true,
                'include_admin_roles' => true,
                'include_is_active' => true,
                'current_password' => false,
                'include_password' => false,
                'include_key' => false,
                'include_site_role_remove' => false,
                'include_site_role_add' => false,
            ]);
        $form->init();
        return $form
            ->get('user-settings');
    }

    public function viewUserDetails(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $this->viewUserData($view, $user, 'common/admin/user-profile');
    }

    public function viewUserShowAfter(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $this->viewUserData($view, $user, 'common/admin/user-profile-list');
    }

    protected function viewUserData(PhpRenderer $view, UserRepresentation $user, $partial): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $elements = $settings->get('userprofile_elements', '');
        if (!$elements) {
            return;
        }

        $fields = $settings->get('userprofile_fields', []) ?: [];
        $exclude = $this->excludedFields('show');
        $fields = array_diff_key($fields, array_flip($exclude));

        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());
        echo $view->partial(
            $partial,
            [
                'user' => $user,
                'userSettings' => $userSettings,
                'fields' => $fields,
            ]
        );
    }

    protected function readConfigElements(): array
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $elements = $settings->get('userprofile_elements');
        if (!$elements) {
            return ['elements' => []];
        }

        try {
            $reader = new IniReader;
            $config = $reader->fromString($elements);
            if ($config && count($config['elements'])) {
                return $config;
            }
        } catch (\Laminas\Config\Exception\RuntimeException $e) {
        }

        try {
            $reader = new XmlReader;
            $config = $reader->fromString($elements);
            if ($config && count($config)) {
                return ['elements' => $config];
            }
        } catch (\Laminas\Config\Exception\RuntimeException $e) {
        }

        try {
            $reader = new JsonReader;
            $config = $reader->fromString($elements);
            if ($config && count($config['elements'])) {
                return $config;
            }
        } catch (\Laminas\Config\Exception\RuntimeException $e) {
        }

        return ['elements' => []];
    }

    protected function excludedFields(string $part): array
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        $isSiteRequest = $status->isSiteRequest();
        $isAdminRequest = $status->isAdminRequest();
        if ($isSiteRequest || $isAdminRequest) {
            $settings = $services->get('Omeka\Settings');
            $exclude = $settings->get('userprofile_exclude', ['admin' => [$part => []], 'public' => [$part => []]]);
            $exclude = $exclude[$isSiteRequest ? 'public' : 'admin'][$part] ?? [];
            if (!is_array($exclude)) {
                $exclude = [];
            }
        } else {
            $exclude = [];
        }
        return $exclude;
    }
}
