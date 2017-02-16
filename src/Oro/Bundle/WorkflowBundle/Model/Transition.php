<?php

namespace Oro\Bundle\WorkflowBundle\Model;

use Doctrine\Common\Collections\Collection;

use Oro\Component\Action\Action\ActionInterface;
use Oro\Component\ConfigExpression\ExpressionInterface;

use Oro\Bundle\WorkflowBundle\Entity\WorkflowItem;
use Oro\Bundle\WorkflowBundle\Exception\ForbiddenTransitionException;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 */
class Transition
{
    /** @var string */
    protected $name;

    /** @var Step */
    protected $stepTo;

    /** @var string */
    protected $label;

    /** @var ExpressionInterface|null */
    protected $condition;

    /** @var ExpressionInterface|null */
    protected $preCondition;

    /** @var ActionInterface|null */
    protected $preAction;

    /** @var ActionInterface|null */
    protected $action;

    /** @var bool */
    protected $start = false;

    /** @var bool */
    protected $hidden = false;

    /** @var array */
    protected $frontendOptions = array();

    /** @var string */
    protected $formType;

    /** @var string */
    protected $displayType;

    /** @var array */
    protected $formOptions = array();

    /** @var string */
    protected $message;

    /** @var bool */
    protected $unavailableHidden = false;

    /** @var string */
    protected $destinationPage;

    /** @var string */
    protected $pageTemplate;

    /** @var string */
    protected $dialogTemplate;

    /** @var string */
    protected $scheduleCron;

    /** @var string */
    protected $scheduleFilter;

    /** @var bool */
    protected $scheduleCheckConditions = false;

    /** @var array */
    protected $initEntities = [];

    /** @var array */
    protected $initRoutes = [];

    /** @var array */
    protected $initDatagrids = [];

    /** @var string */
    protected $initContextAttribute;

    /** @var string */
    protected $pageFormHandler;

    /** @var string */
    protected $pageFormDataAttribute;

    /** @var string */
    protected $pageFormTemplate;

    /** @var string */
    protected $pageFormDataProvider;

    /** @var bool */
    protected $hasPageFormConfiguration = false;

    /**
     * Set label.
     *
     * @param string $label
     * @return Transition
     */
    public function setLabel($label)
    {
        $this->label = $label;
        return $this;
    }

    /**
     * Get label.
     *
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * Set condition.
     *
     * @param ExpressionInterface $condition
     * @return Transition
     */
    public function setCondition(ExpressionInterface $condition = null)
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * Get condition.
     *
     * @return ExpressionInterface|null
     */
    public function getCondition()
    {
        return $this->condition;
    }

    /**
     * Set pre-condition.
     *
     * @param ExpressionInterface|null $condition
     * @return Transition
     */
    public function setPreCondition($condition)
    {
        $this->preCondition = $condition;
        return $this;
    }

    /**
     * Get pre-condition.
     *
     * @return ExpressionInterface|null
     */
    public function getPreCondition()
    {
        return $this->preCondition;
    }

    /**
     * Set name.
     *
     * @param string $name
     * @return Transition
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param ActionInterface $preAction
     * @return Transition
     */
    public function setPreAction(ActionInterface $preAction = null)
    {
        $this->preAction = $preAction;
        return $this;
    }

    /**
     * @return ActionInterface|null
     */
    public function getPreAction()
    {
        return $this->preAction;
    }

    /**
     * @param ActionInterface $action
     * @return Transition
     */
    public function setAction(ActionInterface $action = null)
    {
        $this->action = $action;
        return $this;
    }

    /**
     * @return ActionInterface|null
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Set step to.
     *
     * @param Step $stepTo
     * @return Transition
     */
    public function setStepTo(Step $stepTo)
    {
        $this->stepTo = $stepTo;
        return $this;
    }

    /**
     * Get step to.
     *
     * @return Step
     */
    public function getStepTo()
    {
        return $this->stepTo;
    }

    /**
     * Check is transition condition is allowed for current workflow item.
     *
     * @param WorkflowItem $workflowItem
     * @param Collection|null $errors
     * @return boolean
     */
    protected function isConditionAllowed(WorkflowItem $workflowItem, Collection $errors = null)
    {
        if (!$this->condition) {
            return true;
        }

        return $this->condition->evaluate($workflowItem, $errors) ? true : false;
    }

    /**
     * Check is transition pre condition is allowed for current workflow item.
     *
     * @param WorkflowItem $workflowItem
     * @param Collection|null $errors
     * @return boolean
     */
    protected function isPreConditionAllowed(WorkflowItem $workflowItem, Collection $errors = null)
    {
        if ($this->preAction) {
            $this->preAction->execute($workflowItem);
        }

        if (!$this->preCondition) {
            return true;
        }

        return $this->preCondition->evaluate($workflowItem, $errors) ? true : false;
    }

    /**
     * Check is transition allowed for current workflow item.
     *
     * @param WorkflowItem $workflowItem
     * @param Collection $errors
     * @return bool
     */
    public function isAllowed(WorkflowItem $workflowItem, Collection $errors = null)
    {
        return $this->isPreConditionAllowed($workflowItem, $errors)
            && $this->isConditionAllowed($workflowItem, $errors);
    }

    /**
     * Check that transition is available to show.
     *
     * @param WorkflowItem $workflowItem
     * @param Collection $errors
     * @return bool
     */
    public function isAvailable(WorkflowItem $workflowItem, Collection $errors = null)
    {
        if ($this->hasForm()) {
            return $this->isPreConditionAllowed($workflowItem, $errors);
        } else {
            return $this->isAllowed($workflowItem, $errors);
        }
    }

    /**
     * Run transition process.
     *
     * @param WorkflowItem $workflowItem
     * @throws ForbiddenTransitionException
     */
    public function transit(WorkflowItem $workflowItem)
    {
        if ($this->isAllowed($workflowItem)) {
            $stepTo = $this->getStepTo();
            $workflowItem->setCurrentStep($workflowItem->getDefinition()->getStepByName($stepTo->getName()));

            if ($this->action) {
                $this->action->execute($workflowItem);
            }
        } else {
            throw new ForbiddenTransitionException(
                sprintf('Transition "%s" is not allowed.', $this->getName())
            );
        }
    }

    /**
     * Mark transition as start transition
     *
     * @param boolean $start
     * @return Transition
     */
    public function setStart($start)
    {
        $this->start = $start;
        return $this;
    }

    /**
     * @return bool
     */
    public function isStart()
    {
        return $this->start;
    }

    /**
     * Set frontend options.
     *
     * @param array $frontendOptions
     * @return Transition
     */
    public function setFrontendOptions(array $frontendOptions)
    {
        $this->frontendOptions = $frontendOptions;
        return $this;
    }

    /**
     * Get frontend options.
     *
     * @return array
     */
    public function getFrontendOptions()
    {
        return $this->frontendOptions;
    }

    /**
     * @return bool
     */
    public function hasForm()
    {
        return (!empty($this->formOptions) && !empty($this->formOptions['attribute_fields']))
            || $this->hasPageFormConfiguration();
    }

    /**
     * @param string $formType
     * @return Transition
     */
    public function setFormType($formType)
    {
        $this->formType = $formType;
        return $this;
    }

    /**
     * @return string
     */
    public function getFormType()
    {
        return $this->formType;
    }

    /**
     * @param array $formOptions
     * @return Transition
     */
    public function setFormOptions(array $formOptions)
    {
        $this->formOptions = $formOptions;
        return $this;
    }

    /**
     * @return array
     */
    public function getFormOptions()
    {
        return $this->formOptions;
    }

    /**
     * @return boolean
     */
    public function isHidden()
    {
        return $this->hidden;
    }

    /**
     * @param boolean $hidden
     * @return Transition
     */
    public function setHidden($hidden)
    {
        $this->hidden = $hidden;
        return $this;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param string $message
     * @return Transition
     */
    public function setMessage($message)
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @return boolean
     */
    public function isUnavailableHidden()
    {
        return $this->unavailableHidden;
    }

    /**
     * @param boolean $unavailableHidden
     * @return Transition
     */
    public function setUnavailableHidden($unavailableHidden)
    {
        $this->unavailableHidden = $unavailableHidden;
        return $this;
    }

    /**
     * @return string
     */
    public function getDisplayType()
    {
        return $this->displayType;
    }

    /**
     * @param string $displayType
     * @return Transition
     */
    public function setDisplayType($displayType)
    {
        $this->displayType = $displayType;

        return $this;
    }

    /**
     * @param string $destinationPage
     * @return Transition
     */
    public function setDestinationPage($destinationPage)
    {
        $this->destinationPage = $destinationPage;

        return $this;
    }

    /**
     * @return string
     */
    public function getDestinationPage()
    {
        return $this->destinationPage;
    }

    /**
     * @param string $transitionTemplate
     * @return Transition
     */
    public function setPageTemplate($transitionTemplate)
    {
        $this->pageTemplate = $transitionTemplate;

        return $this;
    }

    /**
     * @return string
     */
    public function getPageTemplate()
    {
        return $this->pageTemplate;
    }

    /**
     * @param string $widgetTemplate
     * @return Transition
     */
    public function setDialogTemplate($widgetTemplate)
    {
        $this->dialogTemplate = $widgetTemplate;

        return $this;
    }

    /**
     * @return string
     */
    public function getDialogTemplate()
    {
        return $this->dialogTemplate;
    }

    /**
     * @param string $cron
     * @return $this
     */
    public function setScheduleCron($cron)
    {
        $this->scheduleCron = (string) $cron;

        return $this;
    }

    /**
     * @return string
     */
    public function getScheduleCron()
    {
        return $this->scheduleCron;
    }

    /**
     * @param string $dqlFilter
     * @return $this
     */
    public function setScheduleFilter($dqlFilter)
    {
        $this->scheduleFilter = (string) $dqlFilter;

        return $this;
    }

    /**
     * @return string
     */
    public function getScheduleFilter()
    {
        return $this->scheduleFilter;
    }

    /**
     * @param bool $scheduleCheckConditions
     * @return $this
     */
    public function setScheduleCheckConditions($scheduleCheckConditions)
    {
        $this->scheduleCheckConditions = $scheduleCheckConditions;

        return $this;
    }

    /**
     * @return bool
     */
    public function isScheduleCheckConditions()
    {
        return $this->scheduleCheckConditions;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->name;
    }

    /**
     * @return array
     */
    public function getInitEntities()
    {
        return $this->initEntities;
    }

    /**
     * @param array $initEntities
     *
     * @return $this
     */
    public function setInitEntities(array $initEntities)
    {
        $this->initEntities = $initEntities;

        return $this;
    }

    /**
     * @return array
     */
    public function getInitRoutes()
    {
        return $this->initRoutes;
    }

    /**
     * @param array $initRoutes
     *
     * @return $this
     */
    public function setInitRoutes(array $initRoutes)
    {
        $this->initRoutes = $initRoutes;

        return $this;
    }

    /**
     * @return array
     */
    public function getInitDatagrids()
    {
        return $this->initDatagrids;
    }

    /**
     * @param array $initDatagrids
     *
     * @return $this
     */
    public function setInitDatagrids(array $initDatagrids)
    {
        $this->initDatagrids = $initDatagrids;

        return $this;
    }

    /**
     * @return bool
     */
    public function isEmptyInitOptions()
    {
        return !count($this->getInitEntities()) && !count($this->getInitRoutes()) && !count($this->getInitDatagrids());
    }

    /**
     * @return string
     */
    public function getInitContextAttribute()
    {
        return $this->initContextAttribute;
    }

    /**
     * @param string $initContextAttribute
     *
     * @return $this
     */
    public function setInitContextAttribute($initContextAttribute)
    {
        $this->initContextAttribute = $initContextAttribute;

        return $this;
    }

    /**
     * @return string
     */
    public function getPageFormHandler()
    {
        return $this->pageFormHandler;
    }

    /**
     * @param string $pageFormHandler
     *
     * @return $this
     */
    public function setPageFormHandler($pageFormHandler)
    {
        $this->pageFormHandler = $pageFormHandler;

        return $this;
    }

    /**
     * @return string
     */
    public function getPageFormDataAttribute()
    {
        return $this->pageFormDataAttribute;
    }

    /**
     * @param string $pageFormDataAttribute
     *
     * @return $this
     */
    public function setPageFormDataAttribute($pageFormDataAttribute)
    {
        $this->pageFormDataAttribute = $pageFormDataAttribute;

        return $this;
    }

    /**
     * @return string
     */
    public function getPageFormTemplate()
    {
        return $this->pageFormTemplate;
    }

    /**
     * @param string $pageFormTemplate
     *
     * @return $this
     */
    public function setPageFormTemplate($pageFormTemplate)
    {
        $this->pageFormTemplate = $pageFormTemplate;

        return $this;
    }

    /**
     * @return string
     */
    public function getPageFormDataProvider()
    {
        return $this->pageFormDataProvider;
    }

    /**
     * @param string $pageFormDataProvider
     *
     * @return $this
     */
    public function setPageFormDataProvider($pageFormDataProvider)
    {
        $this->pageFormDataProvider = $pageFormDataProvider;

        return $this;
    }

    /**
     * @return boolean
     */
    public function hasPageFormConfiguration()
    {
        return $this->hasPageFormConfiguration;
    }

    /**
     * @param boolean $hasPageFormConfiguration
     *
     * @return $this
     */
    public function setHasPageFormConfiguration($hasPageFormConfiguration)
    {
        $this->hasPageFormConfiguration = $hasPageFormConfiguration;

        return $this;
    }
}
