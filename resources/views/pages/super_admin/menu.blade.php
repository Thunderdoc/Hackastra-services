{{-- Platform-only sidebar (owner ruling 2026-10-22, Workstream DD):
     the Super Admin's sidebar is Platform Overview (in the main menu)
     + this Platform group + My Account. The old fragment also linked
     the school Settings page and the school Library — both are school
     domain and are gone; the platform Users module and the Audit Log
     are what remain. --}}
<li class="nav-item-header"><div class="text-uppercase font-size-xs line-height-xs">Platform</div><i class="icon-menu" title="Platform"></i></li>

{{--Platform users--}}
<li class="nav-item">
    <a href="{{ route('users.index') }}" class="nav-link {{ in_array(Route::currentRouteName(), ['users.index', 'users.show', 'users.edit', 'users.create']) ? 'active' : '' }}"><i class="icon-users4"></i> <span>Users</span></a>
</li>

{{--Audit Log--}}
<li class="nav-item">
    <a href="{{ route('audit_logs.index') }}" class="nav-link {{ (Route::is('audit_logs.index')) ? 'active' : '' }}"><i class="icon-history"></i> <span>Audit Log</span></a>
</li>

{{--School Feedback — forwarded reports only (Workstreams CW+DF placement)--}}
<li class="nav-item">
    <a href="{{ route('feedback.sa_index') }}" class="nav-link {{ (Route::is('feedback.sa_*')) ? 'active' : '' }}"><i class="icon-bubbles4"></i> <span>School Feedback</span>
    @if(\Illuminate\Support\Facades\Schema::hasTable('feedback_items'))
        @php($lavFbNew = \App\Models\FeedbackItem::forwarded()->where('sa_status', 'received')->count())
        @if($lavFbNew) <span class="badge badge-danger ml-auto">{{ $lavFbNew }}</span> @endif
    @endif
    </a>
</li>

{{--Platform Mail (Workstream DF)--}}
<li class="nav-item">
    <a href="{{ route('platform_mail.create') }}" class="nav-link {{ (Route::is('platform_mail.create')) ? 'active' : '' }}"><i class="icon-envelop5"></i> <span>Send Mail</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('platform_mail.index') }}" class="nav-link {{ (Route::is('platform_mail.index')) ? 'active' : '' }}"><i class="icon-mail-read"></i> <span>Sent Mail</span></a>
</li>
<li class="nav-item">
    <a href="{{ route('reports.builder') }}" class="nav-link {{ (Route::is('reports.*')) ? 'active' : '' }}"><i class="icon-file-text2"></i> <span>Reports &amp; PDF Builder</span></a>
</li>
