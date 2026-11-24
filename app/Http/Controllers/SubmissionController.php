<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!Auth::user()->isMahasiswa()) {
            abort(403, 'Akses ditolak. Pengumpulan tugas hanya dapat dilakukan oleh Mahasiswa.');
        }

        $user = Auth::user();

        $enrollment = $assignment->course->students()->where('users.id', $user->id)->first();
        if (!$enrollment || $enrollment->pivot->status !== 'approved') {
            abort(403, 'Akses ditolak. Anda belum disetujui oleh Dosen untuk mengikuti mata kuliah tugas ini.');
        }

        if ($assignment->isOverdue()) {
            return back()->with('error', 'Gagal mengumpulkan: Batas waktu pengumpulan (deadline) tugas telah berakhir.');
        }

        $validated = $request->validate([
            'jawaban' => ['required', 'string', 'min:10', 'max:20000'],
        ], [
            'jawaban.required' => 'Teks jawaban tugas wajib diisi.',
            'jawaban.min' => 'Jawaban terlalu pendek. Tuliskan jawaban minimal 10 karakter.',
            'jawaban.max' => 'Jawaban melebihi batas maksimal 20.000 karakter.',
        ]);

        Submission::updateOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ],
            [
                'jawaban' => $validated['jawaban'],
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Jawaban tugas Anda berhasil disimpan ke sistem.');
    }

    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        // TODO 5a (grade): Cek apakah dosen adalah pengampu mata kuliah, validasi nilai (0-100) & catatan_dosen, lalu update submission.
        
        // 1. Pastikan $submission->assignment->course->dosen_id === Auth::id(), jika tidak abort(403).
        if ($submission->assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah ini.');
        }

        // 2. Validasi input 'nilai' (required|integer|min:0|max:100) dan 'catatan_dosen' (nullable|string|max:1000).
        $validated = $request->validate([
            'nilai' => 'required|integer|min:0|max:100',
            'catatan_dosen' => 'nullable|string|max:1000',
        ], [
            'nilai.required' => 'Nilai wajib diisi.',
            'nilai.integer' => 'Nilai harus berupa angka.',
            'nilai.min' => 'Nilai minimal adalah 0.',
            'nilai.max' => 'Nilai maksimal adalah 100.',
            'catatan_dosen.max' => 'Catatan maksimal 1000 karakter.',
        ]);

        // 3. Simpan perubahan menggunakan $submission->update(...) dan redirect back() dengan pesan sukses.
        $submission->update([
            'nilai' => $validated['nilai'],
            'catatan_dosen' => $validated['catatan_dosen'],
        ]);

        return back()->with('success', "Nilai berhasil disimpan. Status: {$submission->status_nilai}");
    }

    public function exportCsv(Assignment $assignment): StreamedResponse
    {
        // TODO 5b (exportCsv): Cek kepemilikan dosen, lalu gunakan response()->streamDownload() dan fputcsv() untuk mengunduh data nilai mahasiswa.
        
        // 1. Pastikan $assignment->course->dosen_id === Auth::id(), jika tidak abort(403).
        if ($assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah ini.');
        }

        // 2. Muat relasi course.approvedStudents dan submissions dari $assignment.
        $assignment->load(['course.approvedStudents', 'submissions']);

        $filename = 'rekap_nilai_' . str_replace(' ', '_', $assignment->judul) . '.csv';

        // 3. Kembalikan response()->streamDownload(...) yang menulis header & baris CSV menggunakan fopen('php://output', 'w') dan fputcsv().
        return response()->streamDownload(function () use ($assignment) {
            $file = fopen('php://output', 'w');
            
            // Header CSV
            fputcsv($file, ['NIM', 'Nama Mahasiswa', 'Nilai', 'Status', 'Catatan Dosen']);

            // Data Baris
            foreach ($assignment->course->approvedStudents as $student) {
                // Cari data submission milik mahasiswa ini pada tugas ini
                $submission = $assignment->submissions->where('user_id', $student->id)->first();
                
                if ($submission) {
                    fputcsv($file, [
                        $student->nomor_induk,
                        $student->name,
                        $submission->nilai ?? '-',
                        $submission->status_nilai,
                        $submission->catatan_dosen ?? '-'
                    ]);
                } else {
                    fputcsv($file, [
                        $student->nomor_induk,
                        $student->name,
                        '-',
                        'Belum Mengumpulkan',
                        '-'
                    ]);
                }
            }

            fclose($file);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}