<?php declare(strict_types = 1);

namespace PHPStan\Command\ErrorFormatter;

use PHPStan\Command\AnalyseCommand;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;

class TableErrorFormatter implements ErrorFormatter
{

	/** @var RelativePathHelper */
	private $relativePathHelper;

	/** @var bool */
	private $showTipsOfTheDay;

	/** @var bool */
	private $checkThisOnly;

	/** @var bool */
	private $inferPrivatePropertyTypeFromConstructor;

	/** @var bool */
	private $checkMissingTypehints;

	public function __construct(
		RelativePathHelper $relativePathHelper,
		bool $showTipsOfTheDay,
		bool $checkThisOnly,
		bool $inferPrivatePropertyTypeFromConstructor,
		bool $checkMissingTypehints
	)
	{
		$this->relativePathHelper = $relativePathHelper;
		$this->showTipsOfTheDay = $showTipsOfTheDay;
		$this->checkThisOnly = $checkThisOnly;
		$this->inferPrivatePropertyTypeFromConstructor = $inferPrivatePropertyTypeFromConstructor;
		$this->checkMissingTypehints = $checkMissingTypehints;
	}

	public function formatErrors(
		AnalysisResult $analysisResult,
		Output $output
	): int
	{
		$projectConfigFile = 'phpstan.neon';
		if ($analysisResult->getProjectConfigFile() !== null) {
			$projectConfigFile = $this->relativePathHelper->getRelativePath($analysisResult->getProjectConfigFile());
		}

		$style = $output->getStyle();

		$showInferPropertiesTip = function () use ($output, $analysisResult, $projectConfigFile): void {
			if (
				$this->checkThisOnly
				|| !$analysisResult->hasInferrablePropertyTypesFromConstructor()
				|| $this->inferPrivatePropertyTypeFromConstructor
			) {
				return;
			}

			$output->writeLineFormatted('💡 Tip of the Day:');
			$output->writeLineFormatted("One or more properties in your code do not have a phpDoc with a type\nbut it could be inferred from the constructor to find more bugs.");
			$output->writeLineFormatted(sprintf('Use <fg=cyan>inferPrivatePropertyTypeFromConstructor: true</> in your <fg=cyan>%s</> to try it out!', $projectConfigFile));
			$output->writeLineFormatted('');
		};

		if (!$analysisResult->hasErrors() && !$analysisResult->hasWarnings()) {
			$style->success('No errors');
			if ($this->showTipsOfTheDay) {
				if ($analysisResult->isDefaultLevelUsed()) {
					$output->writeLineFormatted('💡 Tip of the Day:');
					$output->writeLineFormatted(sprintf(
						"PHPStan is performing only the most basic checks.\nYou can pass a higher rule level through the <fg=cyan>--%s</> option\n(the default and current level is %d) to analyse code more thoroughly.",
						AnalyseCommand::OPTION_LEVEL,
						AnalyseCommand::DEFAULT_LEVEL
					));
					$output->writeLineFormatted('');
				} else {
					$showInferPropertiesTip();
				}
			}

			return 0;
		}

		/** @var array<string, \PHPStan\Analyser\Error[]> $fileErrors */
		$fileErrors = [];
		foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
			if (!isset($fileErrors[$fileSpecificError->getFile()])) {
				$fileErrors[$fileSpecificError->getFile()] = [];
			}

			$fileErrors[$fileSpecificError->getFile()][] = $fileSpecificError;
		}

		foreach ($fileErrors as $file => $errors) {
			$rows = [];
			foreach ($errors as $error) {
				$message = $error->getMessage();
				if ($error->getTip() !== null) {
					$tip = $error->getTip();
					$tip = str_replace('%configurationFile%', $projectConfigFile, $tip);
					$message .= "\n💡 " . $tip;
				}
				$rows[] = [
					(string) $error->getLine(),
					$message,
				];
			}

			$relativeFilePath = $this->relativePathHelper->getRelativePath($file);

			$style->table(['Line', $relativeFilePath], $rows);
		}

		if (count($analysisResult->getNotFileSpecificErrors()) > 0) {
			$style->table(['', 'Error'], array_map(static function (string $error): array {
				return ['', $error];
			}, $analysisResult->getNotFileSpecificErrors()));
		}

		$warningsCount = count($analysisResult->getWarnings());
		if ($warningsCount > 0) {
			$style->table(['', 'Warning'], array_map(static function (string $warning): array {
				return ['', $warning];
			}, $analysisResult->getWarnings()));
		}

		$finalMessage = sprintf($analysisResult->getTotalErrorsCount() === 1 ? 'Found %d error' : 'Found %d errors', $analysisResult->getTotalErrorsCount());
		if ($warningsCount > 0) {
			$finalMessage .= sprintf($warningsCount === 1 ? ' and %d warning' : ' and %d warnings', $warningsCount);
		}

		if ($analysisResult->getTotalErrorsCount() > 0) {
			$style->error($finalMessage);
		} else {
			$style->warning($finalMessage);
		}

		if ($this->checkMissingTypehints && $this->showTipsOfTheDay) {
			$showInferPropertiesTip();
		}

		return $analysisResult->getTotalErrorsCount() > 0 ? 1 : 0;
	}

}
