<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Batch;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeleteLocations
{
    /**
     * Delete locations when every selected location is eligible.
     *
     * @param  Collection<int, Location>  $locations
     */
    public function __invoke(Collection $locations): bool
    {
        $locationIds = $locations
            ->map(static fn (Location $location): string => $location->id)
            ->unique()
            ->values()
            ->all();

        if ($locationIds === []) {
            return true;
        }

        DB::beginTransaction();

        try {
            $lockedLocations = Location::query()
                ->whereKey($locationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedLocations->count() !== count($locationIds)) {
                $this->rollBack();

                return false;
            }

            if (Batch::query()->whereIn('location_id', $locationIds)->exists()) {
                $this->rollBack();

                return false;
            }

            foreach ($lockedLocations as $location) {
                if ($location->delete()) {
                    continue;
                }

                $this->rollBack();

                return false;
            }

            DB::commit();

            return true;
        } catch (QueryException $exception) {
            $this->rollBack();

            if ($this->isLocationForeignKeyViolation($exception)) {
                return false;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }
    }

    private function isLocationForeignKeyViolation(QueryException $exception): bool
    {
        $errorCode = $exception->errorInfo[1] ?? null;

        return $exception->getCode() === '23000'
            && ($errorCode === 1451 || $errorCode === '1451');
    }

    private function rollBack(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
}
