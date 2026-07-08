<?php

declare(strict_types=1);

require_once __DIR__ . '/../Classes/Service/RecommendationGuardrailService.php';
require_once __DIR__ . '/../Classes/Service/RecommendationImpactEvaluationService.php';

use App\SeoAssistant\Service\RecommendationGuardrailService;
use App\SeoAssistant\Service\RecommendationImpactEvaluationService;

$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$instanceWithoutConstructor = static function (string $class): object {
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
};

$callPrivate = static function (object $object, string $method, array $arguments = []) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $arguments);
};

$guardrail = $instanceWithoutConstructor(RecommendationGuardrailService::class);

$validMetadata = $guardrail->validate([
    'action_type' => 'metadata_update',
    'action_payload_json' => json_encode([
        'seo_title' => 'SEO Beratung Karlsruhe | WALDBYTE',
        'description' => 'Pragmatische SEO Beratung fuer TYPO3 Websites in Karlsruhe.',
    ], JSON_THROW_ON_ERROR),
    'recommendation' => 'Title und Description praeziser formulieren.',
]);
$check($validMetadata['valid'] === true, 'Expected normal metadata recommendation to pass guardrails.');
$check($validMetadata['status'] === 'passed', 'Expected normal metadata recommendation status to be passed.');

$longTitle = $guardrail->validate([
    'action_type' => 'metadata_update',
    'proposed_seo_title' => str_repeat('A', 61),
    'proposed_description' => 'Kurz genug.',
    'recommendation' => 'Title kuerzen.',
]);
$check($longTitle['valid'] === false, 'Expected SEO title longer than 60 chars to fail.');

$unsupportedClaim = $guardrail->validate([
    'action_type' => 'metadata_update',
    'proposed_seo_title' => 'SEO Agentur Karlsruhe',
    'proposed_description' => 'Wir garantieren Platz 1 bei Google.',
    'recommendation' => 'Ranking Garantie hervorheben.',
]);
$check($unsupportedClaim['valid'] === false, 'Expected unsupported ranking claim to fail.');

$keywordStuffing = $guardrail->validate([
    'action_type' => 'metadata_update',
    'proposed_seo_title' => 'TYPO3 SEO Karlsruhe',
    'proposed_description' => 'TYPO3 SEO fuer bessere TYPO3 Sichtbarkeit.',
    'recommendation' => 'TYPO3 TYPO3 TYPO3 TYPO3 TYPO3 wiederholen.',
]);
$check($keywordStuffing['valid'] === false, 'Expected aggressive keyword repetition to fail.');

$localInjection = $guardrail->validate([
    'action_type' => 'content_gap_brief',
    'page_url' => 'https://example.test/webdesign',
    'query_text' => 'webdesign agentur',
    'proposed_seo_title' => 'Webdesign Karlsruhe',
    'proposed_description' => 'Webdesign Karlsruhe fuer Unternehmen.',
    'recommendation' => 'Karlsruhe mehrfach einfuegen.',
    'action_payload_json' => json_encode([
        'content_body_html' => '<p>Karlsruhe soll sichtbar sein.</p>',
    ], JSON_THROW_ON_ERROR),
]);
$check($localInjection['valid'] === true, 'Expected local injection warning to remain reviewable.');
$check($localInjection['status'] === 'warning', 'Expected repeated local term usage to produce warning status.');

$invalidSchema = $guardrail->validate([
    'action_type' => 'structured_data_suggestion',
    'action_payload_json' => json_encode([
        'structured_data_preview' => '{"@context": ',
    ], JSON_THROW_ON_ERROR),
    'recommendation' => 'Schema Markup ergaenzen.',
]);
$check($invalidSchema['valid'] === false, 'Expected invalid structured-data JSON preview to fail.');

$impact = $instanceWithoutConstructor(RecommendationImpactEvaluationService::class);
$earlyPreset = $callPrivate($impact, 'evaluationStagePreset', ['early']);
$firstPreset = $callPrivate($impact, 'evaluationStagePreset', ['first']);
$finalPreset = $callPrivate($impact, 'evaluationStagePreset', ['final']);

$check($earlyPreset['stage'] === 'early_signal_14d', 'Expected early preset to map to early_signal_14d.');
$check($earlyPreset['minAgeDays'] === 14, 'Expected early preset to require 14 days.');
$check($firstPreset['stage'] === 'first_evaluation_35d', 'Expected first preset to map to first_evaluation_35d.');
$check($finalPreset['stage'] === 'final_evaluation_90d', 'Expected final preset to map to final_evaluation_90d.');

$check(
    $callPrivate($impact, 'recommendationStatusForEvaluation', ['improved', 'early_signal_14d']) === 'evaluating',
    'Expected early improved signal to keep recommendation evaluating.'
);
$check(
    $callPrivate($impact, 'recommendationStatusForEvaluation', ['not_enough_data', 'final_evaluation_90d']) === 'evaluating',
    'Expected not_enough_data to keep recommendation evaluating.'
);
$check(
    $callPrivate($impact, 'recommendationStatusForEvaluation', ['improved', 'final_evaluation_90d']) === 'improved',
    'Expected final improved evaluation to move recommendation to improved.'
);

$applySource = file_get_contents(__DIR__ . '/../Classes/Service/RecommendationApplyService.php') ?: '';
$verifySource = file_get_contents(__DIR__ . '/../Classes/Service/RecommendationVerificationService.php') ?: '';
$impactSource = file_get_contents(__DIR__ . '/../Classes/Service/RecommendationImpactEvaluationService.php') ?: '';

$check(str_contains($applySource, "['draft', 'approved']"), 'Apply flow should default to draft/approved recommendations only.');
$check(str_contains($applySource, 'RecommendationRollbackService'), 'Apply flow should keep rollback snapshots before writing changes.');
$check(str_contains($applySource, "'status' => 'applied'"), 'Apply flow should write applied status after real changes.');
$check(str_contains($verifySource, "status'] ?? '') !== 'applied'"), 'Verify flow should require applied recommendations.');
$check(str_contains($verifySource, "\$data['status'] = 'verified'"), 'Verify flow should promote passing recommendations to verified.');
$check(str_contains($impactSource, "'applied', 'verified', 'evaluating', 'improved', 'neutral', 'declined'"), 'Impact flow should evaluate the full post-apply lifecycle set.');
$check(str_contains($impactSource, 'experimentDiagnostics'), 'Impact flow should store experiment diagnostics with each evaluation.');
$check(str_contains($impactSource, 'no_holdout_or_control_page_group'), 'Impact diagnostics should disclose missing holdout/control groups.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'SEO Assistant lifecycle checks passed.' . PHP_EOL);
