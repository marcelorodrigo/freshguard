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
            $result = $this->attemptDeletion($locationIds);

            if ($result) {
                DB::commit();
            } else {
                $this->rollBack();
            }

            return $result;
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

    private function attemptDeletion(array $locationIds): bool
    {
        $lockedLocations = Location::query()
            ->whereKey($locationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lockedLocations->count() !== count($locationIds)) {
            return false;
        }

        if (Batch::query()->whereIn('location_id', $locationIds)->exists()) {
            return false;
        }

        return $this->deleteEach($lockedLocations);
    }

    private function deleteEach(Collection $locations): bool
    {
        foreach ($locations as $location) {
            if (! $location->delete()) {
                return false;
            }
        }

        return true;
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
