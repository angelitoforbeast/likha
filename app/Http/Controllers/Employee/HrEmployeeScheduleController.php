<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HrEmployeeSchedule;
use App\Models\ZkUser; // assuming your employees are from zk_users

class HrEmployeeScheduleController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $query = HrEmployeeSchedule::with('employee');

        if ($search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('name', 'like', "%$search%");
            });
        }

        $schedules = $query->orderBy('employee_id')->paginate(20);

        return view('employee.attendance.schedule_index', compact('schedules', 'search'));
    }

    public function create()
    {
        $employees = ZkUser::orderBy('name')->get();
        return view('employee.attendance.schedule_create', compact('employees'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:zk_users,id',
            'time_in' => 'required',
            'time_out' => 'required',
        ]);

        HrEmployeeSchedule::create($request->all());

        return redirect()->route('attendance.schedule.index')
            ->with('status', 'Schedule added successfully.');
    }

    public function edit($id)
    {
        $schedule = HrEmployeeSchedule::findOrFail($id);
        $employees = ZkUser::orderBy('name')->get();
        return view('employee.attendance.schedule_edit', compact('schedule', 'employees'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'employee_id' => 'required|exists:zk_users,id',
            'time_in' => 'required',
            'time_out' => 'required',
        ]);

        $schedule = HrEmployeeSchedule::findOrFail($id);
        $schedule->update($request->all());

        return redirect()->route('attendance.schedule.index')
            ->with('status', 'Schedule updated successfully.');
    }

    public function destroy($id)
    {
        $schedule = HrEmployeeSchedule::findOrFail($id);
        $schedule->delete();

        return redirect()->route('attendance.schedule.index')
            ->with('status', 'Schedule deleted.');
    }
}
