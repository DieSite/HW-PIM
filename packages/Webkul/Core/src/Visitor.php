<?php

namespace Webkul\Core;

use Illuminate\Database\Eloquent\Model;
use Shetabit\Visitor\Models\Visit;
use Shetabit\Visitor\Visitor as BaseVisitor;
use Webkul\Core\Jobs\UpdateCreateVisitIndex;

class Visitor extends BaseVisitor
{
    /**
     * Queue a visit log; the record is written by the job, so nothing is returned.
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    public function visit(?Model $model = null, array $extraAttributes = []): ?Visit
    {
        foreach ($this->except as $path) {
            if ($this->request->is($path)) {
                return null;
            }
        }

        UpdateCreateVisitIndex::dispatch($model, $this->prepareLog());

        return null;
    }

    /**
     * Retrieve request's url
     */
    public function url(): string
    {
        return $this->request->url();
    }

    /**
     * Returns logs
     *
     * @return array
     */
    public function getLog()
    {
        return $this->prepareLog();
    }
}
