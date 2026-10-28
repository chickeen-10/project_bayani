<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Project BAYANI digital repository and content management system for Philippine learning resources across subjects, grade levels, and curricula">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('components.theme-init')
  <title>@yield('title', 'Project BAYANI') — Digital Repository</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bayani-shell" data-page="{{ $page ?? 'home' }}" data-item-id="{{ $itemId ?? '' }}" data-preview-embedded="{{ request()->boolean('embed') ? '1' : '0' }}">
  @php($bayaniAuth = auth()->user() ? [
    'id' => auth()->user()->id,
    'name' => auth()->user()->name,
    'email' => auth()->user()->email,
    'role' => auth()->user()->role,
    'organization' => auth()->user()->organization,
    'contact' => auth()->user()->contact,
    'bio' => auth()->user()->bio,
    'avatar' => auth()->user()->profile_image ? route('profile.avatar', ['v' => hash('sha256', auth()->user()->profile_image)]) : '',
  ] : null)
  <script>
    window.BayaniAuth = @json($bayaniAuth);
    window.BayaniAuthErrors = @json($errors->all());
    window.BayaniFieldErrors = @json($errors->messages());
    window.BayaniOldInput = @json(old());
  </script>
  <a class="skip-link" href="#app-content">Skip to main content</a>
  <script>window.BayaniBackend = @json($backend ?? null);</script>
  @if(session('status'))<div class="container mt-3"><div class="alert alert-success mb-0" role="status">{{ session('status') }}</div></div>@endif

  @include('components.navbar')
  <main class="container bayani-main" id="app-content" aria-live="polite" tabindex="-1"></main>
  <footer class="footer py-5 mt-auto"><div class="container"><div class="row g-4 footer-columns"><div class="col-md-5"><div class="d-flex align-items-center gap-2 mb-3"><span class="brand-mark">B</span><span class="footer-title">Project BAYANI</span></div><p class="mb-2">Digital Educational Resource Repository &amp; CMS</p><p class="small-label mb-0">Learning resources across Philippine subjects, grade levels, and curricula.</p></div><div class="col-6 col-md-3"><div class="footer-title mb-3">Explore</div><div class="d-flex flex-column gap-2"><a href="{{ route('repository') }}">Repository</a><a href="{{ route('about') }}">About the project</a><a href="{{ route('help') }}">Help Center</a></div></div><div class="col-6 col-md-4">
  <div class="footer-title mb-3">Get started</div>
  <div class="d-flex flex-column gap-2">
    @auth
      @if(auth()->user()->role === 'admin')
        <a href="{{ route('admin.queue') }}">Review submissions</a>
      @else
        <a href="{{ route('contributor.submissions') }}">My submissions</a>
      @endif
    @else
      <a href="{{ route('login') }}">Sign in</a>
      <a href="{{ route('register') }}">Join as a contributor</a>
    @endauth
  </div>
</div></div><div class="border-top mt-4 pt-3 d-flex flex-column flex-md-row justify-content-between gap-2 small-label"><span>Resource metadata is stored in MariaDB. Attachments use private server storage and are served according to approval and ownership.</span><span>© Project BAYANI</span></div></div></footer>
  <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-host"></div>
  <div class="modal fade" id="confirm-modal" tabindex="-1" aria-labelledby="confirm-title" aria-describedby="confirm-message" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="confirm-title">Please confirm</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="confirm-message">Continue?</div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" id="confirm-action">Confirm</button></div></div></div></div>
</body>
</html>
