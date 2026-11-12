<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

// Owner and staff: notices for all tenants
class AnnouncementController extends Controller
{
    // GET /api/announcements
    public function index()
    {
        return response()->json([
            'data' => Announcement::latest('starts_on')->latest('id')->limit(100)->get()->map->present(),
        ]);
    }

    // POST /api/announcements
    public function store(Request $request)
    {
        $announcement = Announcement::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['message' => 'Notice published.', 'data' => $announcement->present()], 201);
    }

    // PUT /api/announcements/{announcement}
    public function update(Request $request, Announcement $announcement)
    {
        $announcement->update($this->validated($request));

        return response()->json(['message' => 'Notice updated.', 'data' => $announcement->present()]);
    }

    // DELETE /api/announcements/{announcement}
    public function destroy(Announcement $announcement)
    {
        $announcement->delete();

        return response()->json(['message' => 'Notice removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'     => ['required', 'string', 'max:150'],
            'body'      => ['required', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on'   => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
    }
}
