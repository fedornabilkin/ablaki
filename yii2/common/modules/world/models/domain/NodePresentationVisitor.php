<?php
namespace common\modules\world\models\domain;

/** Normalizes the batch-loaded DTO without per-kind UI branches or private-data expansion. */
final class NodePresentationVisitor implements WorldVisitor
{
    private $policy;
    private $user;
    private $presentation;
    public function __construct(NodePolicy $policy, int $user, array $presentation) { $this->policy = $policy; $this->user = $user; $this->presentation = $presentation; }
    public function visit(WorldComponent $component): array
    {
        $row = $component->record(); $dto = $this->presentation;
        $details = (array)$dto['details'];
        $common = ['condition' => (int)($details['condition'] ?? $row['condition'] ?? 0), 'max_condition' => (int)($details['max_condition'] ?? $row['max_condition'] ?? 100),
            'repair_active' => (bool)($details['repair_active'] ?? false)];
        if ($dto['permissions']['manage']) $common += ['area' => (int)($row['area'] ?? 0), 'exposure_class' => $row['exposure_class'] ?? 'outdoor'];
        $dto['details'] = (object)($common + $details);
        $dto['has_finances'] = true;
        $effective = array_merge($row, $details, $common);
        $dto['actions'] = $this->policy->actions($effective + ['depth' => 0], $this->user);
        $dto['extensions'] = $this->policy->extensions($effective);
        return $dto;
    }
}
