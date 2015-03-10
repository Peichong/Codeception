<?php
namespace Codeception\Lib\Generator;

use Codeception\Codecept;
use Codeception\Configuration;
use Codeception\Lib\Di;
use Codeception\Lib\ModuleContainer;
use Codeception\Util\Template;

class Actions
{

    protected $template = <<<EOF
<?php  //[STAMP] {{hash}}
namespace {{namespace}}_generated;

// This class was automatically generated by build task
// You should not change it manually as it will be overwritten on next build
// @codingStandardsIgnoreFile

{{use}}

trait {{name}}Actions
{
   {{methods}}
}
EOF;


    protected $methodTemplate = <<<EOF

    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     {{doc}}
     * @see \{{module}}::{{method}}()
     */
    public function {{action}}({{params}}) {
        return \$this->scenario->runStep(new \Codeception\Step\{{step}}('{{method}}', func_get_args()));
    }
EOF;

    protected $name;
    protected $settings;
    protected $modules;
    protected $actions;
    protected $numMethods = 0;

    public function __construct($settings)
    {
        $this->name = $settings['class_name'];
        $this->settings = $settings;
        $this->di = new Di();
        $modules = Configuration::modules($this->settings);
        $this->moduleContainer = new ModuleContainer($this->di, $settings);
        foreach ($modules as $moduleName) {
            $this->modules[$moduleName] = $this->moduleContainer->create($moduleName);
        }
        $this->actions = $this->moduleContainer->getActions();
    }


    public function produce()
    {
        $namespace = rtrim($this->settings['namespace'], '\\');

        $uses = [];
        foreach ($this->modules as $module) {
            $uses[] = "use " . get_class($module) . ";";
        }

        $methods = [];
        $code = [];
        foreach ($this->actions as $action => $moduleName) {
            if (in_array($action, $methods)) {
                continue;
            }
            $class = new \ReflectionClass($this->modules[$moduleName]);
            $method = $class->getMethod($action);
            $code[] = $this->addMethod($method);
            $methods[] = $action;
            $this->numMethods++;
        }

        return (new Template($this->template))
            ->place('namespace', $namespace ? $namespace . '\\' : '')
            ->place('hash', self::genHash($this->actions, $this->settings))
            ->place('name', $this->name)
            ->place('use', implode("\n", $uses))
            ->place('methods', implode("\n\n ", $code))
            ->produce();
    }

    protected function addMethod(\ReflectionMethod $refMethod)
    {
        $class = $refMethod->getDeclaringClass();
        $params = $this->getParamsString($refMethod);
        $module = $class->getName();

        $body = '';
        $doc = $this->addDoc($class, $refMethod);
        $doc = str_replace('/**', '', $doc);
        $doc = trim(str_replace('*/', '', $doc));
        if (!$doc) {
            $doc = "*";
        }

        $conditionalDoc = $doc . "\n     * Conditional Assertion: Test won't be stopped on fail";

        $methodTemplate = (new Template($this->methodTemplate))
            ->place('module', $module)
            ->place('method', $refMethod->name)
            ->place('params', $params);

        // generate conditional assertions
        if (0 === strpos($refMethod->name, 'see')) {
            $type = 'Assertion';
            $body .= $methodTemplate
                ->place('doc', $conditionalDoc)
                ->place('action', 'can' . ucfirst($refMethod->name))
                ->place('step', 'ConditionalAssertion')
                ->produce();

            // generate negative assertion
        } elseif (0 === strpos($refMethod->name, 'dontSee')) {
            $type = 'Assertion';
            $body .= $methodTemplate
                ->place('doc', $conditionalDoc)
                ->place('action', str_replace('dont', 'cant', $refMethod->name))
                ->place('step', 'ConditionalAssertion')
                ->produce();

        } elseif (0 === strpos($refMethod->name, 'am')) {
            $type = 'Condition';
        } else {
            $type = 'Action';
        }

        $body .= $methodTemplate
            ->place('doc', $doc)
            ->place('action', $refMethod->name)
            ->place('step', $type)
            ->produce();

        return $body;
    }

    /**
     * @param \ReflectionMethod $refMethod
     * @return array
     */
    protected function getParamsString(\ReflectionMethod $refMethod)
    {
        $params = [];
        foreach ($refMethod->getParameters() as $param) {

            if ($param->isOptional()) {
                $params[] = '$' . $param->name . ' = null';
            } else {
                $params[] = '$' . $param->name;
            };

        }
        return implode(', ', $params);
    }

    /**
     * @param \ReflectionClass $class
     * @param \ReflectionMethod $refMethod
     * @return string
     */
    protected function addDoc(\ReflectionClass $class, \ReflectionMethod $refMethod)
    {
        $doc = $refMethod->getDocComment();

        if (!$doc) {
            $interfaces = $class->getInterfaces();
            foreach ($interfaces as $interface) {
                $i = new \ReflectionClass($interface->name);
                if ($i->hasMethod($refMethod->name)) {
                    $doc = $i->getMethod($refMethod->name)->getDocComment();
                    break;
                }
            }
        }

        if (!$doc and $class->getParentClass()) {
            $parent = new \ReflectionClass($class->getParentClass()->name);
            if ($parent->hasMethod($refMethod->name)) {
                $doc = $parent->getMethod($refMethod->name)->getDocComment();
                return $doc;
            }
            return $doc;
        }
        return $doc;
    }

    public static function genHash($actions, $settings)
    {
        return md5(Codecept::VERSION . serialize($actions) . serialize($settings['modules']));
    }

    public function getNumMethods()
    {
        return $this->numMethods;
    }


} 