@php use Pterodactyl\Uptime\Http\Format; @endphp
<section class="ut-section" aria-labelledby="source-release">
    <div class="ut-card">
        <h2 id="source-release">Source code &amp; release</h2>
        <dl class="ut-kv">
            <dt>Source repository</dt>
            <dd>@if($repository)<a href="{{ $repository }}" rel="noopener">{{ $repository }}</a>@else not configured @endif</dd>
            <dt>Collector release</dt>
            <dd>{{ $release['version'] }}@if($release['installed_at']) · installed {{ $release['installed_at'] }}@endif</dd>
            <dt>Collector source commit</dt>
            <dd>
                @if($url = $commitUrl($release['commit']))<a class="ut-mono" href="{{ $url }}" rel="noopener">{{ $release['commit'] }}</a>
                @else <span class="ut-muted">unknown (not installed from a tagged release)</span>@endif
            </dd>
            <dt>Collector files</dt>
            <dd>
                @if(is_null($release['matches']))<span class="ut-muted">no release manifest</span>
                @elseif($release['matches'])<span class="ut-badge ok">match the release manifest</span>
                @else<span class="ut-badge warn">differ from the release manifest</span>@endif
                <span class="ut-muted ut-small">(self-check by the collector: SHA-256 <span class="ut-mono">{{ Format::short($release['installed_digest'], 16) }}</span>)</span>
            </dd>
            <dt>Verification guide</dt>
            <dd>@if($repository)<a href="{{ $repository }}/blob/main/docs/verification.md" rel="noopener">docs/verification.md</a>@else — @endif</dd>
        </dl>
        <p class="ut-small ut-muted" style="margin:12px 0 0">Build and checksum details are self-reported by the collector and by each signed agent event. Compare them with the published releases; a match shows consistency, not that the server is unmodified.</p>
    </div>
</section>
