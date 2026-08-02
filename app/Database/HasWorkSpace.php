<?php
namespace App\Database;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class HasWorkSpace extends SimplifiedOneRelation  {

       public function __construct(
		Model $parent,
		Model $related
	) {
		parent::__construct($parent, $related);
	}

	public function addEagerConstraints(array $models): void
	{
		$this->query->where(function(Builder $query) use ($models) {
			foreach ($models as $parent) {
				$query->whereExists(function($query) use ($parent){
					$table="workspaceables";
					$workspaceable_type= $parent::class;
					$workspaceable_id = $parent->id;

					$query->select(DB::raw(1))
						->from($table)
						// This links the subquery to the outer "workspaces" query
						->whereColumn("$table.workspace_id", "workspaces.id") 
						// These filter by the specific polymorphic parent
						->where('workspaceable_type', $workspaceable_type)
						->where('workspaceable_id', $workspaceable_id);
				});
			}
		});

		
	}
	
	public function match(array $models, EloquentCollection $results, $relation): array
	{
		foreach($models as $model)
		{
			$model->setRelation($relation, $results->first());
		}
		return $models;
	}

	public function getResults()
	{
		$results = parent::getResults();
		return $results->first();

	}
	
	// public function getEager()
	// {
	// 	return $this->query->get();
	// }
}
