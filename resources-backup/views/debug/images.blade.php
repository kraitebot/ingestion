<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Debug Screenshots</title>
    @vite(['resources/css/app.css'])
    <style>
        .drop-zone {
            border: 2px dashed rgba(239, 68, 68, 0.3);
            transition: all 0.3s ease;
        }
        .drop-zone.dragover {
            border-color: #ef4444;
            background-color: rgba(239, 68, 68, 0.05);
        }
    </style>
</head>
<body class="bg-[#0c0a0b] text-slate-100 antialiased selection:bg-red-500/30">
    {{-- Dotted background --}}
    <div aria-hidden="true" class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(139,30,30,0.18)_1px,transparent_0)] [background-size:24px_24px]"></div>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">Debug Files</h1>
                <p class="text-sm text-white/60">Upload and manage debug files (images, HTML, text) for analysis</p>
            </div>
            <button id="clearAll" class="inline-flex items-center gap-2 px-6 py-3 bg-red-500/10 hover:bg-red-500/20 border border-red-400/30 hover:border-red-400/50 rounded-lg font-semibold transition text-red-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Clear All Files
            </button>
        </div>

        <!-- Upload Zone -->
        <div id="dropZone" class="drop-zone rounded-2xl bg-white/5 border-white/10 p-12 text-center mb-8 cursor-pointer shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
            <div class="inline-grid h-16 w-16 place-items-center rounded-full bg-red-500/10 border border-red-400/30 mb-4">
                <svg class="h-8 w-8 text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
            </div>
            <p class="text-lg mb-2 font-medium">Drop files here, click to select, or paste (Ctrl+V)</p>
            <p class="text-sm text-white/60">Supports images, HTML, TXT, JSON, XML, LOG, MD • Max 10MB per file</p>
            <input type="file" id="fileInput" multiple accept="image/*,.html,.txt,.json,.xml,.log,.md" class="hidden">
        </div>

        <!-- Upload Status -->
        <div id="uploadStatus" class="hidden mb-6 p-4 rounded-lg bg-emerald-500/10 border border-emerald-400/30 text-emerald-200"></div>

        <!-- File Gallery -->
        <div id="imageGallery" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse($files as $file)
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset] relative group">
                    <button
                        onclick="deleteImage('{{ $file['name'] }}')"
                        class="absolute top-6 right-6 bg-red-500/20 hover:bg-red-500/30 border border-red-400/40 hover:border-red-400/60 text-red-300 rounded-full w-9 h-9 inline-grid place-items-center opacity-0 group-hover:opacity-100 transition z-10 font-bold text-xl leading-none"
                        title="Delete file"
                    >
                        <span class="-translate-y-[3px]">×</span>
                    </button>

                    @if($file['is_image'])
                        <img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" class="w-full rounded-lg mb-3 cursor-pointer hover:opacity-80 transition border border-white/5" onclick="window.open('{{ $file['url'] }}', '_blank')">
                    @elseif($file['is_text'])
                        <div class="mb-3 p-4 bg-black/30 rounded-lg border border-white/5 max-h-48 overflow-auto cursor-pointer hover:bg-black/40 transition" onclick="window.open('{{ $file['url'] }}', '_blank')">
                            <pre class="text-xs text-white/70 whitespace-pre-wrap break-words font-mono">{{ Str::limit($file['content'], 500) }}</pre>
                        </div>
                    @else
                        <div class="mb-3 p-8 bg-black/30 rounded-lg border border-white/5 text-center cursor-pointer hover:bg-black/40 transition" onclick="window.open('{{ $file['url'] }}', '_blank')">
                            <svg class="mx-auto h-12 w-12 text-white/30 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <p class="text-xs text-white/50 uppercase">{{ $file['extension'] }} file</p>
                        </div>
                    @endif

                    <p class="text-sm text-white/80 truncate font-medium" title="{{ $file['name'] }}">{{ $file['name'] }}</p>
                    <p class="text-xs text-white/50 mt-1">{{ number_format($file['size'] / 1024, 2) }} KB • {{ strtoupper($file['extension']) }}</p>
                </div>
            @empty
                <div class="col-span-full text-center py-16 rounded-2xl border border-white/10 bg-white/5">
                    <svg class="mx-auto h-12 w-12 text-white/30 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <p class="text-white/60">No files uploaded yet</p>
                    <p class="text-sm text-white/40 mt-1">Upload your first file to get started</p>
                </div>
            @endforelse
        </div>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const clearAllBtn = document.getElementById('clearAll');
        const uploadStatus = document.getElementById('uploadStatus');
        const imageGallery = document.getElementById('imageGallery');
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        // Click to upload
        dropZone.addEventListener('click', () => fileInput.click());

        // File input change
        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                uploadFiles(Array.from(e.target.files));
            }
        });

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');

            const files = Array.from(e.dataTransfer.files).filter(file => file.type.startsWith('image/'));
            if (files.length > 0) {
                uploadFiles(files);
            }
        });

        // Paste from clipboard
        document.addEventListener('paste', (e) => {
            const items = e.clipboardData.items;
            const files = [];

            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    files.push(items[i].getAsFile());
                }
            }

            if (files.length > 0) {
                uploadFiles(files);
            }
        });

        // Upload files
        async function uploadFiles(files) {
            const formData = new FormData();
            files.forEach((file, index) => {
                formData.append(`images[${index}]`, file);
            });

            try {
                const response = await fetch('/debug-images', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showStatus(`Successfully uploaded ${data.count} file(s)!`, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showStatus(data.message || 'Upload failed. Please try again.', 'error');
                }
            } catch (error) {
                showStatus('Upload error: ' + error.message, 'error');
            }
        }

        // Delete single file
        window.deleteImage = async function(filename) {
            if (!confirm('Delete this file?')) {
                return;
            }

            try {
                const response = await fetch('/debug-images', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ filename })
                });

                const data = await response.json();

                if (data.success) {
                    showStatus('Image deleted!', 'success');
                    setTimeout(() => window.location.reload(), 500);
                }
            } catch (error) {
                showStatus('Delete error: ' + error.message, 'error');
            }
        };

        // Clear all images
        clearAllBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to delete all files?')) {
                return;
            }

            try {
                const response = await fetch('/debug-images', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({})
                });

                const data = await response.json();

                if (data.success) {
                    showStatus(`Deleted ${data.deleted} file(s)!`, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showStatus(data.message || 'Delete failed', 'error');
                }
            } catch (error) {
                showStatus('Delete error: ' + error.message, 'error');
            }
        });

        // Show status message
        function showStatus(message, type) {
            uploadStatus.textContent = message;
            uploadStatus.className = type === 'success'
                ? 'mb-6 p-4 rounded-lg bg-emerald-500/10 border border-emerald-400/30 text-emerald-200'
                : 'mb-6 p-4 rounded-lg bg-red-500/10 border border-red-400/30 text-red-200';
            uploadStatus.classList.remove('hidden');

            setTimeout(() => {
                uploadStatus.classList.add('hidden');
            }, 5000);
        }
    </script>
</body>
</html>
