<?php declare(strict_types = 1);

namespace PHPStan\Rules\Methods;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\ClassCaseSensitivityCheck;
use PHPStan\Rules\FunctionCallParametersCheck;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\ErrorType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\VerbosityLevel;

class CallStaticMethodsRule implements \PHPStan\Rules\Rule
{

	/** @var \PHPStan\Broker\Broker */
	private $broker;

	/** @var \PHPStan\Rules\FunctionCallParametersCheck */
	private $check;

	/** @var \PHPStan\Rules\RuleLevelHelper */
	private $ruleLevelHelper;

	/** @var \PHPStan\Rules\ClassCaseSensitivityCheck */
	private $classCaseSensitivityCheck;

	/** @var bool */
	private $checkFunctionNameCase;

	public function __construct(
		Broker $broker,
		FunctionCallParametersCheck $check,
		RuleLevelHelper $ruleLevelHelper,
		ClassCaseSensitivityCheck $classCaseSensitivityCheck,
		bool $checkFunctionNameCase
	)
	{
		$this->broker = $broker;
		$this->check = $check;
		$this->ruleLevelHelper = $ruleLevelHelper;
		$this->classCaseSensitivityCheck = $classCaseSensitivityCheck;
		$this->checkFunctionNameCase = $checkFunctionNameCase;
	}

	public function getNodeType(): string
	{
		return StaticCall::class;
	}

	/**
	 * @param \PhpParser\Node\Expr\StaticCall $node
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return string[]
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!is_string($node->name) && !$node->name instanceof Node\Identifier) {
			return [];
		}
		$methodName = (string) $node->name;

		$class = $node->class;
		$errors = [];
		if ($class instanceof Name) {
			$className = (string) $class;
			$lowercasedClassName = strtolower($className);
			if (in_array($lowercasedClassName, ['self', 'static'], true)) {
				if (!$scope->isInClass()) {
					return [
						sprintf(
							'Calling %s::%s() outside of class scope.',
							$class,
							$methodName
						),
					];
				}
				$className = $scope->getClassReflection()->getName();
			} elseif ($lowercasedClassName === 'parent') {
				if (!$scope->isInClass()) {
					return [
						sprintf(
							'Calling %s::%s() outside of class scope.',
							$className,
							$methodName
						),
					];
				}
				$currentClassReflection = $scope->getClassReflection();
				if ($currentClassReflection->getParentClass() === false) {
					return [
						sprintf(
							'%s::%s() calls parent::%s() but %s does not extend any class.',
							$scope->getClassReflection()->getDisplayName(),
							$scope->getFunctionName(),
							$methodName,
							$scope->getClassReflection()->getDisplayName()
						),
					];
				}

				if ($scope->getFunctionName() === null) {
					throw new \PHPStan\ShouldNotHappenException();
				}

				$className = $currentClassReflection->getParentClass()->getName();
			} else {
				if (!$this->broker->hasClass($className)) {
					return [
						sprintf('Call to static method %s() on an unknown class %s.', $methodName, $className),
					];
				} else {
					$errors = $this->classCaseSensitivityCheck->checkClassNames([$className]);
				}

				$className = $this->broker->getClass($className)->getName();
			}

			$classType = new ObjectType($className);
		} else {
			$classTypeResult = $this->ruleLevelHelper->findTypeToCheck(
				$scope,
				$class,
				sprintf('Call to static method %s() on an unknown class %%s.', $methodName),
				function (Type $type) use ($methodName): bool {
					return $type->canCallMethods()->yes() && $type->hasMethod($methodName);
				}
			);
			$classType = $classTypeResult->getType();
			if ($classType instanceof ErrorType) {
				return $classTypeResult->getUnknownClassErrors();
			}
		}

		if ($classType instanceof StringType) {
			return [];
		}

		$typeForDescribe = $classType;
		$classType = TypeCombinator::remove($classType, new StringType());

		if (!$classType->canCallMethods()->yes()) {
			return array_merge($errors, [
				sprintf('Cannot call static method %s() on %s.', $methodName, $typeForDescribe->describe(VerbosityLevel::typeOnly())),
			]);
		}

		if (!$classType->hasMethod($methodName)) {
			return array_merge($errors, [
				sprintf(
					'Call to an undefined static method %s::%s().',
					$typeForDescribe->describe(VerbosityLevel::typeOnly()),
					$methodName
				),
			]);
		}

		$method = $classType->getMethod($methodName, $scope);
		if (!$method->isStatic()) {
			$function = $scope->getFunction();
			if (
				!$function instanceof MethodReflection
				|| $function->isStatic()
				|| !$scope->isInClass()
				|| (
					$classType instanceof TypeWithClassName
					&& $scope->getClassReflection()->getName() !== $classType->getClassName()
					&& !$scope->getClassReflection()->isSubclassOf($classType->getClassName())
				)
			) {
				return array_merge($errors, [
					sprintf(
						'Static call to instance method %s::%s().',
						$method->getDeclaringClass()->getDisplayName(),
						$method->getName()
					),
				]);
			}
		}

		if (!$scope->canCallMethod($method)) {
			$errors = array_merge($errors, [
				sprintf(
					'Call to %s %s %s() of class %s.',
					$method->isPrivate() ? 'private' : 'protected',
					$method->isStatic() ? 'static method' : 'method',
					$method->getName(),
					$method->getDeclaringClass()->getDisplayName()
				),
			]);
		}

		$lowercasedMethodName = sprintf(
			'%s %s',
			$method->isStatic() ? 'static method' : 'method',
			$method->getDeclaringClass()->getDisplayName() . '::' . $method->getName() . '()'
		);
		$displayMethodName = sprintf(
			'%s %s',
			$method->isStatic() ? 'Static method' : 'Method',
			$method->getDeclaringClass()->getDisplayName() . '::' . $method->getName() . '()'
		);

		$errors = array_merge($errors, $this->check->check(
			ParametersAcceptorSelector::selectFromArgs(
				$scope,
				$node->args,
				$method->getVariants()
			),
			$scope,
			$node,
			[
				$displayMethodName . ' invoked with %d parameter, %d required.',
				$displayMethodName . ' invoked with %d parameters, %d required.',
				$displayMethodName . ' invoked with %d parameter, at least %d required.',
				$displayMethodName . ' invoked with %d parameters, at least %d required.',
				$displayMethodName . ' invoked with %d parameter, %d-%d required.',
				$displayMethodName . ' invoked with %d parameters, %d-%d required.',
				'Parameter #%d %s of ' . $lowercasedMethodName . ' expects %s, %s given.',
				'Result of ' . $lowercasedMethodName . ' (void) is used.',
				'Parameter #%d %s of ' . $lowercasedMethodName . ' is passed by reference, so it expects variables only.',
			]
		));

		if (
			$this->checkFunctionNameCase
			&& $method->getName() !== $methodName
		) {
			$errors[] = sprintf('Call to %s with incorrect case: %s', $lowercasedMethodName, $methodName);
		}

		return $errors;
	}

}
