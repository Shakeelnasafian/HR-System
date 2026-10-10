<?php
namespace App\Workforce;

use App\Audit\Audit;
use App\Http\Resources\ProjectedRow;
use App\Tenancy\ScopesCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Effective-dated assignment history, reporting lines and employment-level probation (EMP-02/03). */
final class AssignmentController
{
    use ScopesCompany;
    public function __construct(private Assignments $assignments) {}

    private function employment(string $company, string $id, bool $lock=false): object
    {
        abort_unless(Str::isUuid($id),404);
        $q=$this->rows('employments',$company)->where('id',$id);
        $row=($lock?$q->lockForUpdate():$q)->first(); abort_unless($row,404); return $row;
    }
    public function index(string $company, string $employment): array
    {
        $this->company($company,'workforce.read'); $row=$this->employment($company,$employment);
        return ['data'=>$this->assignments->query($company)->where('a.employment_id',$row->id)->get()->map(fn($a)=>Assignments::present($a))];
    }
    /** Unspecified fields copy from the assignment in effect on effective_from; explicit null clears. */
    public function store(Request $r, string $company, string $employment)
    {
        $this->company($company,'workforce.write'); $this->employment($company,$employment);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>'required|string|max:500','effective_from'=>'required|date_format:Y-m-d']+Assignments::rules('sometimes'));
        $explicit=array_intersect_key($data,array_flip(Assignments::KEYS)); $date=$data['effective_from'];
        $reporting=!empty($explicit['manager_employment_id']);
        if($reporting) { $this->assignments->lockReportingLines($company); } // before the row lock, always in this order
        $row=$this->employment($company,$employment,true);
        abort_unless($row->version===$data['version'],409,'This employment changed. Reload before continuing.');
        abort_if($row->status==='cancelled',409,'Cancelled employments cannot change assignments.');
        if($date<$row->start_date||($row->end_date&&$date>=$row->end_date)) { throw ValidationException::withMessages(['effective_from'=>'The effective date must fall within the employment (from start date, before end date).']); }
        if($this->rows('employment_assignments',$company)->where('employment_id',$row->id)->where('effective_from',$date)->exists()) { throw ValidationException::withMessages(['effective_from'=>'An assignment already starts on this date.']); }
        $base=$this->assignments->inEffect($company,$row->id,$date);
        $before=array_map(fn($k)=>$base?->$k,array_combine(Assignments::KEYS,Assignments::KEYS));
        $values=array_merge($before,$explicit);
        // Copied-forward references are re-validated too: a new row must not re-assert an archived record or an ended/cancelled manager.
        $this->assignments->check($company,$values,$date,$row->employee_id,array_keys(array_diff_key($values,$explicit)));
        $id=$this->assignments->insert($company,$row->id,$date,$values,$data['reason']);
        if($reporting) {
            $next=$this->rows('employment_assignments',$company)->where('employment_id',$row->id)->where('effective_from','>',$date)->min('effective_from');
            $this->assignments->assertAcyclic($company,$row->id,$date,$next);
        }
        $this->rows('employments',$company)->where('id',$row->id)->update(['version'=>$row->version+1,'updated_at'=>now()]);
        $changed=array_keys(array_filter($values,fn($v,$k)=>$before[$k]!==$v,ARRAY_FILTER_USE_BOTH));
        Audit::record($company,'employment.assignment_added',$row->id,['assignment_id'=>$id,'effective_from'=>$date,'fields'=>$changed],$data['reason']);
        return response()->json(['data'=>Assignments::present($this->assignments->query($company)->where('a.id',$id)->first())+['employment_version'=>$row->version+1]],201);
    }
    /** Direct reports whose assignment in effect on the company's today names this employment and whose employment covers today. */
    public function reports(Request $r, string $company, string $employment): JsonResource
    {
        $c=$this->company($company,'workforce.read'); $row=$this->employment($company,$employment);
        $r->validate(['page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:100']);
        $today=now($c->timezone)->toDateString();
        $current=$this->rows('employment_assignments',$company)->selectRaw('DISTINCT ON (employment_id) employment_id, manager_employment_id')
            ->where('effective_from','<=',$today)->orderBy('employment_id')->orderByDesc('effective_from');
        $q=DB::query()->fromSub($current,'cur')
            ->join('employments as j',fn($j)=>$j->on('j.id','=','cur.employment_id')->where('j.tenant_id',$this->tenant())->where('j.company_id',$company))
            ->join('employees as e',fn($j)=>$j->on('e.id','=','j.employee_id')->on('e.tenant_id','=','j.tenant_id'))
            ->where('cur.manager_employment_id',$row->id)->where('j.status','<>','cancelled')->where('j.start_date','<=',$today)
            ->where(fn($q)=>$q->whereNull('j.end_date')->orWhere('j.end_date','>',$today))
            ->orderBy('e.legal_name')->orderBy('j.id')
            ->select(['j.id as employment_id','j.employment_number','j.status','e.id as employee_id','e.employee_number','e.legal_name','e.preferred_name']);
        return ProjectedRow::collection($q->paginate((int)$r->input('per_page',25)));
    }
    public function update(Request $r, string $company, string $employment): array
    {
        $c=$this->company($company,'workforce.write'); $this->employment($company,$employment);
        $data=$r->validate(['version'=>'required|integer|min:1','reason'=>'required|string|max:500','probation_end_date'=>'present|nullable|date_format:Y-m-d']);
        $row=$this->employment($company,$employment,true);
        abort_unless($row->version===$data['version'],409,'This employment changed. Reload before continuing.');
        abort_if($row->status==='cancelled',409,'Cancelled employments cannot be changed.');
        if($data['probation_end_date']!==null&&$data['probation_end_date']<$row->start_date) { throw ValidationException::withMessages(['probation_end_date'=>'The probation end date cannot be before the start date.']); }
        if($row->probation_end_date!==$data['probation_end_date']) {
            $this->rows('employments',$company)->where('id',$row->id)->update(['probation_end_date'=>$data['probation_end_date'],'version'=>$row->version+1,'updated_at'=>now()]);
            Audit::record($company,'employment.updated',$row->id,['fields'=>['probation_end_date']],$data['reason']);
        }
        $fresh=$this->rows('employments',$company)->where('id',$row->id)->get(Assignments::EMPLOYMENT);
        return ['data'=>$this->assignments->withCurrent($company,$fresh,now($c->timezone)->toDateString())->first()];
    }
}
