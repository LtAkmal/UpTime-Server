<section class="ut-section ut-explain" aria-labelledby="how-verified">
    <div class="ut-card">
        <h2 id="how-verified">How uptime is verified</h2>
        <p class="ut-muted">Cryptographically signed and publicly auditable uptime records. Monitoring data is independently verifiable from published source code, release metadata, signed events, and hash-chain proofs.</p>
        <ol>
            <li>Each node runs an open-source agent that creates its own Ed25519 key pair. The private key never leaves the node; the collector only stores the public key, whose fingerprint is shown on each node page.</li>
            <li>Every {{ $intervalHint ?? '30 seconds' }} the agent sends a signed heartbeat with a sequence number, its clock, time since boot and its own build checksum. Unsigned, replayed, out-of-order or badly timed events are rejected.</li>
            <li>Accepted events form a hash chain: each event includes the hash of the previous one. Removing, reordering or changing any stored event breaks every later link, and the agent's signature covers the link.</li>
            <li>The collector re-verifies every signature and chain link every hour; anyone can do the same with the public proof of a node and the <code>uptime-verify</code> tool from the source repository.</li>
            <li>Uptime is calculated with a published formula from the receipt times of valid heartbeats. Time before monitoring began is not counted; a reboot always counts as downtime.</li>
        </ol>
        <div class="ut-formula ut-mono" aria-label="Uptime formula">uptime % = (monitoring window − confirmed downtime) ÷ monitoring window × 100<br>downtime starts when no valid heartbeat arrives within timeout + tolerance, and ends with the next valid heartbeat</div>
        <h3 style="margin-top:18px">Limitations</h3>
        <ul>
            <li><strong>Current deployment is not independently operated by a third-party monitor.</strong> The collector and the monitored nodes are run by the same operator, and the initial deployment may run the panel and a node on the same machine.</li>
            <li>Public source code alone cannot prove that the software running on these servers is unmodified. Someone with root, database, deployment, signing-key or DNS control could alter the system; signatures and hash chains make such changes detectable after the fact, not impossible.</li>
            <li>If the collector itself is unreachable, nodes appear offline for that time.</li>
            <li>Independent external monitors (run by a different provider on a different network) are planned and will be listed here when they exist. Independent monitors so far: <strong>0</strong>.</li>
        </ul>
    </div>
</section>
