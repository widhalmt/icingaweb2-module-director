<?php

namespace Icinga\Module\Director\Forms;

use Icinga\Exception\ConfigurationError;
use Icinga\Module\Director\CustomVariable\CustomVariables;
use Icinga\Module\Director\Web\Form\DirectorObjectForm;
use Icinga\Application\Hook;
use Exception;

class DirectorDatafieldForm extends DirectorObjectForm
{
    protected $objectName = 'Data field';

    protected function onRequest()
    {
        if ($this->hasBeenSent()) {

            if ($this->shouldBeDeleted()) {
                $varname = $this->getSentValue('varname');
                if ($cnt = CustomVariables::countAll($varname, $this->getDb())) {
                    $this->askForVariableDeletion($varname, $cnt);
                }

            }
        }

        return parent::onRequest();
    }

    protected function askForVariableDeletion($varname, $cnt)
    {
        $msg = $this->translate(
            'Leaving custom variables in place while removing the related field is'
            . ' perfectly legal and might be a desired operation. This way you can'
            . ' no longer modify related custom variables in the Director GUI, but'
            . ' the variables themselves will stay there and continue to be deployed.'
            . ' When you re-add a field for the same variable later on, everything'
            . ' will continue to work as before'
        );

        $this->addBoolean('wipe_vars', array(
            'label'       => $this->translate('Wipe related vars'),
            'description' => sprintf($msg, $this->getSentValue('varname')),
            'required'    => true,
        ));

        if ($wipe = $this->getSentValue('wipe_vars')) {
            if ($wipe === 'y') {
                CustomVariables::deleteAll($varname, $this->getDb());
            }
        } else {
            $this->abortDeletion();
            $this->addError(
                sprintf(
                    $this->translate('Also wipe all "%s" custom variables from %d objects?'),
                    $varname,
                    $cnt
                )
            );
            $this->getElement('wipe_vars')->addError(
                sprintf(
                    $this->translate(
                        'There are %d objects with a related property. Should I also'
                        . ' remove the "%s" property from them?'
                    ),
                    $cnt,
                    $varname
                )
            );
        }
    }

    public function setup()
    {
        $this->addHtmlHint(
            $this->translate('Data fields allow you to customize input controls your custom variables.')
        );

        $this->addElement('text', 'varname', array(
            'label'       => $this->translate('Field name'),
            'description' => $this->translate('The unique name of the field'),
            'required'    => true,
        ));

        $this->addElement('text', 'caption', array(
            'label'       => $this->translate('Caption'),
            'required'    => true,
            'description' => $this->translate('The caption which should be displayed')
        ));

        $this->addElement('textarea', 'description', array(
            'label'       => $this->translate('Description'),
            'description' => $this->translate('A description about the field'),
            'rows'        => '3',
        ));

        $error = false;
        try {
            $types = $this->enumDataTypes();
        } catch (Exception $e) {
            $error = $e->getMessage();
            $types = $this->optionalEnum(array());
        }
        
        $this->addElement('select', 'datatype', array(
            'label'         => $this->translate('Data type'),
            'description'   => $this->translate('Field type'),
            'required'      => true,
            'multiOptions'  => $types,
            'class'         => 'autosubmit',
        ));
        if ($error) {
            $this->getElement('datatype')->addError($error);
        }

        try {
            if ($class = $this->getSentValue('datatype')) {
                if ($class && array_key_exists($class, $types)) {
                    $this->addSettings($class);
                }
            } elseif ($class = $this->object()->datatype) {
                $this->addSettings($class);
            }

            // TODO: next line looks like obsolete duplicate code to me
            $this->addSettings();
        } catch (Exception $e) {
            $this->getElement('datatype')->addError($e->getMessage());
        }

        foreach ($this->object()->getSettings() as $key => $val) {
            if ($el = $this->getElement($key)) {
                $el->setValue($val);
            }
        }

        $this->setButtons();
    }

    protected function addSettings($class = null)
    {
        if ($class === null) {
            $class = $this->getValue('datatype');
        }

        if ($class !== null) {
            if (! class_exists($class)) {
                throw new ConfigurationError(
                    'The hooked class "%s" for this data field does no longer exist',
                    $class
                );
            }

            $class::addSettingsFormFields($this);
        }
    }

    protected function clearOutdatedSettings()
    {
        $names = array();
        $object = $this->object();
        $global = array('varname', 'description', 'caption', 'datatype');

        foreach ($this->getElements() as $el) {
            if ($el->getIgnore()) {
                continue;
            }

            $name = $el->getName();
            if (in_array($name, $global)) {
                continue;
            }

            $names[$name] = $name;
        }


        foreach ($object->getSettings() as $setting => $value) {
            if (! array_key_exists($setting, $names)) {
                unset($object->$setting);
            }
        }
    }

    public function onSuccess()
    {
        $this->clearOutdatedSettings();

        if ($class = $this->getValue('datatype')) {
            if (array_key_exists($class, $this->enumDataTypes())) {
                $this->addHidden('format', $class::getFormat());
            }
        }

        parent::onSuccess();
    }

    protected function enumDataTypes()
    {
        $hooks = Hook::all('Director\\DataType');
        $enum = array(null => '- please choose -');
        foreach ($hooks as $hook) {
            $enum[get_class($hook)] = $hook->getName();
        }

        return $enum;
    }
}
