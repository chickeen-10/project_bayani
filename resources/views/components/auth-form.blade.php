<section class="auth-layout" aria-labelledby="auth-title">
  <header class="auth-intro">
    <div class="eyebrow">{{ $register ? 'JOIN THE COMMUNITY' : 'WELCOME BACK' }}</div>
    <h1 id="auth-title">{{ $register ? 'Your knowledge can make a difference.' : 'A shared space for better learning.' }}</h1>
    <p>{{ $register ? 'Join educators sharing useful resources for Philippine classrooms. Create your account to start contributing.' : 'Sign in to manage your resources, follow review updates, and keep your favorite materials close.' }}</p>
    <div class="auth-benefits"><i class="bi bi-journal-bookmark" aria-hidden="true"></i><span>Discover. Contribute. Inspire.<small>Part of the Project BAYANI learning community.</small></span></div>
    <a href="{{ route('repository') }}" class="section-link">Explore the library <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
  </header>
  <form class="auth-form" method="POST" action="{{ $register ? route('register.store') : route('login') }}">
    @csrf
    <h2>{{ $register ? 'Create your account' : 'Sign in' }}</h2>
    <p class="text-secondary mb-4">{{ $register ? 'A few details, a world of shared learning.' : 'Welcome back to Project BAYANI.' }}</p>
    @if($errors->any())
      <div class="alert alert-danger" role="alert"><strong>Please check your details.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if($register)
      <div class="mb-3"><label class="form-label" for="auth-name">Full name <span aria-hidden="true">*</span></label><input id="auth-name" name="name" class="form-control" autocomplete="name" value="{{ old('name') }}" required maxlength="255"></div>
    @endif
    <div class="mb-3"><label class="form-label" for="auth-email">Email address <span aria-hidden="true">*</span></label><input id="auth-email" name="email" class="form-control @error('email') is-invalid @enderror" type="email" autocomplete="email" value="{{ old('email', request('email')) }}" required maxlength="255" @error('email') aria-invalid="true" @enderror></div>
    <div class="mb-3"><label class="form-label" for="auth-password">Password <span aria-hidden="true">*</span></label><input id="auth-password" name="password" class="form-control" type="password" autocomplete="{{ $register ? 'new-password' : 'current-password' }}" required @if($register) minlength="8" aria-describedby="password-help" @endif>@if($register)<div class="form-text" id="password-help">Use at least 8 characters.</div>@endif</div>
    @if($register)
      <div class="mb-3"><label class="form-label" for="auth-confirm">Confirm password <span aria-hidden="true">*</span></label><input id="auth-confirm" name="password_confirmation" class="form-control" type="password" autocomplete="new-password" required minlength="8"></div>
      <p class="small text-secondary">Your contributor account lets you submit materials for review before they appear in the public library.</p>
    @else
      <div class="form-check mb-4"><input class="form-check-input" id="remember" type="checkbox" name="remember" value="1"><label class="form-check-label" for="remember">Remember me</label></div>
    @endif
    <button class="btn btn-primary w-100" type="submit">{{ $register ? 'Create contributor account' : 'Sign in' }} <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    <p class="small text-secondary mt-4 mb-0">{{ $register ? 'Already part of BAYANI?' : 'New to the community?' }} <a href="{{ $register ? route('login') : route('register') }}">{{ $register ? 'Sign in' : 'Create an account' }}</a></p>
  </form>
</section>
