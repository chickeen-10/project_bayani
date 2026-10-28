<nav class="navbar navbar-expand-lg topbar sticky-top" aria-label="Main navigation">
  <div class="container py-1">
    <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('home') }}" aria-label="Project BAYANI home">
      <span class="brand-mark">B</span><span><span class="brand-name d-block">Project BAYANI</span><span class="brand-sub">Educational Resource Repository</span></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle main navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <li class="nav-item"><a class="nav-link bayani-primary-link {{ request()->routeIs('home') ? 'active' : '' }}" @if(request()->routeIs('home')) aria-current="page" @endif href="{{ route('home') }}">Home</a></li>
        <li class="nav-item"><a class="nav-link bayani-primary-link {{ request()->routeIs('repository', 'search', 'material', 'preview', 'materials.*', 'materials.attachments.*') ? 'active' : '' }}" @if(request()->routeIs('repository', 'search', 'material', 'preview', 'materials.*', 'materials.attachments.*')) aria-current="page" @endif href="{{ route('repository') }}">Repository</a></li>
        @auth
          @if(auth()->user()->role === 'admin')
            <li class="nav-item"><a class="nav-link bayani-primary-link {{ request()->routeIs('admin.*') ? 'active' : '' }}" @if(request()->routeIs('admin.*')) aria-current="page" @endif href="{{ route('admin.dashboard') }}">Dashboard</a></li>
          @else
            <li class="nav-item"><a class="nav-link bayani-primary-link {{ request()->routeIs('contributor.*') ? 'active' : '' }}" @if(request()->routeIs('contributor.*')) aria-current="page" @endif href="{{ route('contributor.dashboard') }}">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link bayani-primary-link upload-nav-link {{ request()->routeIs('contributor.upload', 'contributor.submissions.store') ? 'active' : '' }}" @if(request()->routeIs('contributor.upload')) aria-current="page" @endif href="{{ route('contributor.upload') }}">Upload Material</a></li>
          @endif
          @php($headerUnread = auth()->user()->unreadNotifications()->count())
          @php($headerNotifications = auth()->user()->notifications()->latest()->limit(4)->get())
          <li class="nav-item dropdown ms-lg-2">
            <button class="btn btn-sm btn-light position-relative navbar-utility" id="notification-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications, {{ $headerUnread }} unread" title="Notifications">
              <i class="bi bi-bell" aria-hidden="true"></i><span id="notification-count" data-unread-count="{{ $headerUnread }}" class="notification-count {{ $headerUnread ? '' : 'd-none' }}">{{ $headerUnread > 99 ? '99+' : $headerUnread }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-menu p-0" id="notification-menu">
              <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom"><strong>Notifications</strong><a href="{{ route('notifications') }}" class="small">View all</a></div>
              <div id="notification-preview" class="notification-preview">
                @forelse($headerNotifications as $notice)
                  @php($noticeData = $notice->data ?? [])
                  <a href="{{ route('notifications') }}" class="notification-item {{ $notice->read_at ? '' : 'unread' }}"><span class="notification-dot" aria-hidden="true"></span><span><strong>{{ $noticeData['title'] ?? 'Material update' }}</strong><span class="d-block small text-secondary">{{ $noticeData['message'] ?? '' }}</span><span class="small-label">{{ $notice->created_at?->diffForHumans() }}</span></span></a>
                @empty
                  <div class="p-3 small text-secondary">No notifications yet.</div>
                @endforelse
              </div>
            </div>
          </li>
          <li class="nav-item"><a class="btn btn-sm btn-light navbar-utility" aria-label="Favorites" title="Favorites" @if(request()->routeIs('favorites')) aria-current="page" @endif href="{{ route('favorites') }}"><i class="bi bi-bookmark" aria-hidden="true"></i></a></li>
          <li class="nav-item dropdown ms-lg-1">
            <button class="btn btn-sm btn-light navbar-utility profile-menu-trigger" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Profile menu for {{ auth()->user()->name }}" title="Profile">
              <x-user-avatar :user="auth()->user()" id="nav-avatar" class="avatar avatar-sm" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li class="dropdown-header">{{ auth()->user()->name }} · {{ auth()->user()->role === 'admin' ? 'Administrator' : 'Contributor' }}</li>
              
              <li><a class="dropdown-item {{ request()->routeIs('profile') ? 'active' : '' }}" href="{{ route('profile') }}"><i class="bi bi-person me-2" aria-hidden="true"></i>My Profile</a></li>
              <li><a class="dropdown-item {{ request()->routeIs('account.settings') ? 'active' : '' }}" href="{{ route('account.settings') }}"><i class="bi bi-gear me-2" aria-hidden="true"></i>Account Settings</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="{{ route('about') }}"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>About</a></li>
              <li><a class="dropdown-item" href="{{ route('help') }}"><i class="bi bi-question-circle me-2" aria-hidden="true"></i>Help</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Logout</button></form></li>
            </ul>
          </li>
        @else
          <li class="nav-item"><a class="nav-link {{ request()->routeIs('about') ? 'active' : '' }}" @if(request()->routeIs('about')) aria-current="page" @endif href="{{ route('about') }}">About</a></li>
          <li class="nav-item"><a class="nav-link {{ request()->routeIs('help', 'help.feedback') ? 'active' : '' }}" @if(request()->routeIs('help', 'help.feedback')) aria-current="page" @endif href="{{ route('help') }}">Help</a></li>
          <li class="nav-item ms-lg-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('login') }}">Sign in</a></li>
          <li class="nav-item"><a class="btn btn-sm btn-primary" href="{{ route('register') }}">Join as a contributor</a></li>
        @endauth
      </ul>
    </div>
  </div>
</nav>
