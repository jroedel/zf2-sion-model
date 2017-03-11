<?php
/**
 * Zend Framework (http://framework.zend.com/)
*
* @link      http://github.com/zendframework/ZendSkeletonModule for the canonical source repository
* @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
* @license   http://framework.zend.com/license/new-bsd New BSD License
*/

namespace SionModel\Controller;

use Zend\View\Model\ViewModel;
use Zend\Mvc\Controller\AbstractActionController;
use SionModel\Db\Model\SionTable;
use SionModel\Service\EntitiesService;
use SionModel;
use SionModel\Form\SionForm;
use SionModel\Entity\Entity;
use Zend\Mvc\Controller\Plugin\FlashMessenger;
use JTranslate\Controller\Plugin\NowMessenger;

class SionController extends AbstractActionController
{
    /**
     * @var Entity[] $entitySpecifications
     */
    protected $entitySpecifications;
    /**
     * @var Entity $entitySpecification
     */
    protected $entitySpecification;
    /**
     * @var SionTable $sionTable
     */
    protected $sionTable;
    /**
    * @var string $entity
    */
    protected $entity;
    /**
    * @var defaultRedirectRoute $defaultRedirectRoute
    */
    protected $defaultRedirectRoute;
    /**
    * @var array $sionModelConfig
    */
    protected $sionModelConfig;

    /**
     * @param string $entity
     * @throws \Exception
     */
    public function __construct($entity = null)
    {
        //@todo should we throw an exception if we don't get an $entity?
        $this->setEntity($entity);
    }

    public function indexAction()
    {
        /** @var SionTable $table */
        $table      = $this->getSionTable();
        $entity     = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        $objects    = $table->getObjects($entity);
        $view = new ViewModel([
            'entity'    => $entity,
            'entitySpec'=> $entitySpec,
            'objects'   => $objects,
        ]);

        return $view;
    }

    public function createAction()
    {
        $sm = $this->getServiceLocator ();
        /** @var SionTable $table **/
        $table = $this->getSionTable();
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();

        if (is_null($entitySpec->createActionForm)) {
            throw new \InvalidArgumentException('If the createAction for \''.$entity.'\' is to be used, it must specify the create_action_form configuration.');
        }
        /** @var SionForm $form **/
        if ($sm->has($entitySpec->createActionForm)) {
            $form = $sm->get($entitySpec->createActionForm);
        } elseif (class_exists($entitySpec->createActionForm)) {
            //@todo test this line
            $form = new $entitySpec->createActionForm;
        } else {
            throw new \InvalidArgumentException('Invalid create_action_form specified for \''.$entity.'\' entity.');
        }

        $request = $this->getRequest();
        if ($request->isPost ()) {
            $data = $request->getPost ()->toArray ();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                if (!($newId = $table->createEntity($entity, $data))) {
                    $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                } else {
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )
                        ->addMessage ( ucwords($entity).' successfully created.' );
                    $this->redirectAfterCreate((int) $newId);
                }
            } else {
                $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        }
        $view = new ViewModel([
            'form' => $form,
        ]);

        //check if the user has the createActionTemplate option set, if not they'll go to the default
        if (!is_null($entitySpec->createActionTemplate)) {
            $template = $entitySpec->createActionTemplate;
            $view->setTemplate($template);
        }
        return $view;
    }

    /**
     * This function is called after a successful entity creation to redirect the user.
     * May be overwritten by a child Controller to add functionality.
     * @param int $newId
     * @throws \Exception
     */
    public function redirectAfterCreate($newId)
    {
        $sm = $this->getServiceLocator ();
        /** @var SionTable $table **/
        $table = $this->getSionTable();
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();

        //check if user has the redirect route set
        if (!is_null($entitySpec->createActionRedirectRoute)) {
            if (is_null($entitySpec->createActionRedirectRouteKeyField) ||
                $entitySpec->createActionRedirectRouteKeyField == $entitySpec->entityKeyField ||
                is_null($entitySpec->createActionRedirectRouteKey)
            ) {
                $this->redirect ()->toRoute ($entitySpec->createActionRedirectRoute,
                    !is_null($entitySpec->createActionRedirectRouteKey) ?
                    [$entitySpec->createActionRedirectRouteKey => $newId] : []);
            } else {
                $entityObj = $table->getObject($entity, $newId);
                if (!key_exists($entitySpec->createActionRedirectRouteKeyField, $entityObj)) {
                    throw new \Exception('create_action_redirect_route_key_field is misconfigured for entity \''.$entity.'\'');
                }
                $this->redirect ()->toRoute ($entitySpec->createActionRedirectRoute,
                    [$entitySpec->createActionRedirectRouteKey => $entityObj[$entitySpec->createActionRedirectRouteKeyField]]);
            }
        } else {
            $this->redirect ()->toRoute ($this->getDefaultRedirectRoute());
        }
    }

    /**
     * Standard edit action which checks edit_route_key input, looks up the entity,
     * checks for a post, validates the data with the form and submits the change if the form validates.
     * @throws \Exception
     * @throws \InvalidArgumentException
     * @return ViewModel
     */
    public function editAction()
    {
        $entity = $this->getEntity();
        $entitySpec = $this->getEntitySpecification();
        if (is_null($entitySpec->editRouteKey)) {
            throw new \Exception("Please set the edit_route_key config key of $entity in order to use the editAction.");
        }
        $id = ( int ) $this->params ()->fromRoute ( $entitySpec->editRouteKey );
        //if the entity doesn't exist, redirect to the index or the default route
        if (! $id) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute);
        }
        $entityObject = $this->getEntityObject($id);
        //if the entity doesn't exist, redirect to the index or the default route
        if (is_null($entityObject)) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( ucfirst($entity).' not found.' );
            $redirectRoute = $entitySpec->indexRoute ? $entitySpec->indexRoute : $this->getDefaultRedirectRoute();
            $this->redirect ()->toRoute ( $redirectRoute );
        }

        if (is_null($entitySpec->editActionForm)) {
            throw new \InvalidArgumentException('If the editAction for \''.$entity.'\' is to be used, it must specify the edit_action_form configuration.');
        }
        /** @var SionForm $form **/
        if ($sm->has($entitySpec->editActionForm)) {
            $form = $sm->get($entitySpec->editActionForm);
        } elseif (class_exists($entitySpec->editActionForm)) {
            $className = $entitySpec->editActionForm;
            $form = new $className();
        } else {
            throw new \InvalidArgumentException('Invalid edit_action_form specified for \''.$entity.'\' entity.');
        }

        $request = $this->getRequest ();
        if ($request->isPost ()) {
            $data = $request->getPost ()->toArray ();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                /** @var SionTable $table **/
                $table = $this->getSionTable();
                $table->updateEntity($entity, $id, $data);
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )->addMessage ( ucfirst($entity).' successfully updated.' );
                $entityObject = $this->getEntityObject($id);
                if ($entitySpec->showRouteKey && $entitySpec->showRouteKey &&
                    $entitySpec->showRouteKeyField
                ) {
                    if (!key_exists($entitySpec->showRouteKeyField, $entityObject)) {
                        throw new \Exception("show_route_key_field config for entity '$entity' refers to a key that doesn't exist");
                    }
                    $this->redirect ()->toRoute ($entitySpec->showRoute,
                        [$entitySpec->showRouteKey => $entityObject[$entitySpec->showRouteKeyField]]);
                } else {
                    $this->redirect ()->toRoute ( $this->getDefaultRedirectRoute() );
                }
            } else {
                $this->nowMessenger ()->setNamespace ( NowMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        } else {
            $form->setData($entityObject);
        }
        $view = new ViewModel([
            'entity'    => $entityObject,
            'entityId'  => $id,
            'form'      => $form,
        ]);

        //check if the user has the createActionTemplate option set, if not they'll go to the default
        if (!is_null($entitySpec->editActionTemplate)) {
            $template = $entitySpec->editActionTemplate;
            $view->setTemplate($template);
        }
        return $view;
    }

    /**
     * Get the entitySpecification value
     * @return Entity
     */
    public function getEntitySpecification()
    {
        if (is_null($this->entitySpecification)) {
            $entity = $this->getEntity();
            $entities = $this->getEntitySpecifications();
            if (!key_exists($entity, $entities)) {
                throw new \Exception('Invalid entity given\''.$entity.'\'');
            }
            $this->setEntitySpecification($entities[$entity]);
        }
        return $this->entitySpecification;
    }

    /**
     *
     * @param Entity $entitySpecification
     * @return self
     */
    public function setEntitySpecification(Entity $entitySpecification)
    {
        $this->entitySpecification = $entitySpecification;
        return $this;
    }

    /**
     * Get the array of entity specifications from config
     * @return \SionModel\Entity\Entity[]
     */
    public function getEntitySpecifications()
    {
        if (is_null($this->entitySpecifications)) {
            $sm = $this->getServiceLocator();
            /** @var EntitiesService $entitiesService */
            $entitiesService = $sm->get('SionModel\Service\EntitiesService');
            $this->entitySpecifications = $entitiesService->getEntities();
        }
        return $this->entitySpecifications;
    }

    public function getEntityObject($id)
    {
        $table = $this->getSionTable();
        return $table->getObject($this->getEntity(), $id);
    }

    /**
     * Get the sionTable value
     * @return SionTable
     */
    public function getSionTable()
    {
        if (is_null($this->sionTable)) {
            $sm = $this->getServiceLocator();
            $entitySpec = $this->getEntitySpecification();
            if (!$sm->has($entitySpec->sionModelClass)) {
                throw new \Exception('Invalid SionModel class set for entity \''.$this->getEntity().'\'');
            }
            $this->setSionTable($sm->get($entitySpec->sionModelClass));
        }
        return $this->sionTable;
    }

    /**
     * @param SionTable $sionTable
     * @return self
     */
    public function setSionTable($sionTable)
    {
        if (!$sionTable instanceof SionTable) {
            throw new \Exception('Expecting SionModelClass to be a SionTable instance.');
        }
        $this->sionTable = $sionTable;
        return $this;
    }

    /**
     * Get the entity value
     * @return string
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     *
     * @param string $entity
     * @return self
     */
    public function setEntity($entity)
    {
        $this->entity = $entity;
        return $this;
    }

    /**
     * Get the defaultRedirectRoute value
     * @return defaultRedirectRoute
     */
    public function getDefaultRedirectRoute()
    {
        if (is_null($this->defaultRedirectRoute)) {
            $config = $this->getSionModelConfig();
            $redirectRoute = $config['default_redirect_route'];
            $this->setDefaultRedirectRoute($redirectRoute);
        }
        var_dump($this->defaultRedirectRoute);
        return $this->defaultRedirectRoute;
    }

    /**
     *
     * @param defaultRedirectRoute $defaultRedirectRoute
     * @return self
     */
    public function setDefaultRedirectRoute($defaultRedirectRoute)
    {
        $this->defaultRedirectRoute = $defaultRedirectRoute;
        return $this;
    }

    /**
     * Get the sionModelConfig value
     * @return array
     */
    public function getSionModelConfig()
    {
        if (is_null($this->sionModelConfig)) {
            $sm = $this->getServiceLocator();
            $config = $sm->get('SionModel\Config');
            $this->setSionModelConfig($config);
        }
        return $this->sionModelConfig;
    }

    /**
     *
     * @param array $sionModelConfig
     * @return self
     */
    public function setSionModelConfig(array $sionModelConfig)
    {
        $this->sionModelConfig = $sionModelConfig;
        return $this;
    }
}