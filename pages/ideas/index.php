<?php
/**
 * Ideenbox - Idea Board with Voting
 * Access: member, candidate, head, board
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Idea.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

if (!Auth::canAccessPage('ideas')) {
    header('Location: ../dashboard/index.php');
    exit;
}

$csrfToken = CSRFHandler::getToken();
$ideas     = Idea::getAll((int) $user['id']);

// Fetch submitter names from user DB
$userDb      = Database::getUserDB();
$userInfoMap = [];
if (!empty($ideas)) {
    $uids = array_unique(array_column($ideas, 'user_id'));
    $ph   = str_repeat('?,', count($uids) - 1) . '?';
    $stmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
    $stmt->execute($uids);
    foreach ($stmt->fetchAll() as $u) {
        $userInfoMap[$u['id']] = $u['email'];
    }
}

$statusConfig = [
    'new'         => ['label' => 'Neu',          'dot' => 'bg-sky-400',     'badge' => 'bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 ring-1 ring-sky-400/30'],
    'in_review'   => ['label' => 'In Prüfung',   'dot' => 'bg-amber-400',   'badge' => 'bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 ring-1 ring-amber-400/30'],
    'accepted'    => ['label' => 'Angenommen',   'dot' => 'bg-green-500',   'badge' => 'bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 ring-1 ring-green-500/30'],
    'rejected'    => ['label' => 'Abgelehnt',    'dot' => 'bg-red-500',     'badge' => 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 ring-1 ring-red-500/30'],
    'implemented' => ['label' => 'Umgesetzt',    'dot' => 'bg-purple-500',  'badge' => 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 ring-1 ring-purple-500/30'],
];

$title = 'Ideenbox - IBC Intranet';
ob_start();
?>

<div class="max-w-5xl mx-auto">

    <!-- Hero Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <div class="w-11 h-11 rounded-2xl bg-yellow-100 dark:bg-yellow-900/40 flex items-center justify-center shadow-sm">
                    <i class="fas fa-lightbulb text-yellow-500 text-xl drop-shadow-[0_0_6px_rgba(250,204,21,0.5)]"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Ideenbox</h1>
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Teile Deine Ideen – stimme ab, was umgesetzt werden soll.</p>
        </div>
        <button
            id="openIdeaModal"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-semibold rounded-xl shadow-sm hover:shadow-md transition-all text-sm flex-shrink-0"
        >
            <i class="fas fa-plus"></i>
            Neue Idee
        </button>
    </div>

    <!-- Idea Cards -->
    <?php if (empty($ideas)): ?>
    <div class="py-20 text-center">
        <div class="w-20 h-20 mx-auto mb-5 rounded-full bg-yellow-50 dark:bg-yellow-900/20 flex items-center justify-center">
            <i class="fas fa-lightbulb text-4xl text-yellow-400"></i>
        </div>
        <p class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-2">Noch keine Ideen vorhanden</p>
        <p class="text-gray-500 dark:text-gray-400 mb-6 text-sm">Sei der Erste und reiche Deine Idee ein!</p>
        <button onclick="document.getElementById('openIdeaModal').click()"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-semibold rounded-xl shadow-sm transition-all text-sm">
            <i class="fas fa-plus"></i>Neue Idee einreichen
        </button>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 gap-4" id="ideas-list">
        <?php foreach ($ideas as $idea):
            $submitterEmail = $userInfoMap[$idea['user_id']] ?? 'unknown@example.com';
            $submitterName  = formatEntraName(explode('@', $submitterEmail)[0]);
            $initials       = strtoupper(substr($submitterName, 0, 2));
            $avatarColor    = getAvatarColor($submitterName);
            $sc             = $statusConfig[$idea['status']] ?? $statusConfig['new'];
            $userVote       = $idea['user_vote'] ?? null;
            $upvotes        = (int) ($idea['upvotes'] ?? 0);
            $downvotes      = (int) ($idea['downvotes'] ?? 0);
        ?>
        <div class="group bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm hover:shadow-md transition-all duration-200 flex gap-0 overflow-hidden"
             data-idea-id="<?php echo $idea['id']; ?>">

            <!-- Vote Column -->
            <div class="flex flex-col items-center justify-center gap-1 px-4 py-5 bg-gray-50 dark:bg-gray-800/60 border-r border-gray-100 dark:border-gray-800 min-w-[64px]">
                <button
                    onclick="castVote(<?php echo $idea['id']; ?>, 'up')"
                    title="Upvote"
                    class="vote-btn upvote w-9 h-9 rounded-xl flex items-center justify-center transition-all <?php echo $userVote === 'up' ? 'bg-green-500 text-white shadow-md' : 'bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-green-50 dark:hover:bg-green-900/30 hover:text-green-500 dark:hover:text-green-400 border border-gray-200 dark:border-gray-600'; ?>"
                >
                    <i class="fas fa-chevron-up text-sm"></i>
                </button>
                <span class="vote-score text-sm font-bold <?php echo ($upvotes - $downvotes) > 0 ? 'text-green-600 dark:text-green-400' : (($upvotes - $downvotes) < 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'); ?>">
                    <?php echo $upvotes - $downvotes; ?>
                </span>
                <button
                    onclick="castVote(<?php echo $idea['id']; ?>, 'down')"
                    title="Downvote"
                    class="vote-btn downvote w-9 h-9 rounded-xl flex items-center justify-center transition-all <?php echo $userVote === 'down' ? 'bg-red-500 text-white shadow-md' : 'bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-red-50 dark:hover:bg-red-900/30 hover:text-red-500 dark:hover:text-red-400 border border-gray-200 dark:border-gray-600'; ?>"
                >
                    <i class="fas fa-chevron-down text-sm"></i>
                </button>
            </div>

            <!-- Content -->
            <div class="flex-1 p-5 min-w-0">
                <div class="flex items-start justify-between gap-3 mb-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-50 text-base leading-snug"><?php echo htmlspecialchars($idea['title']); ?></h3>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium <?php echo $sc['badge']; ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?php echo $sc['dot']; ?>"></span>
                            <?php echo $sc['label']; ?>
                        </span>
                        <?php if (Auth::isBoard()): ?>
                        <div class="relative">
                            <button
                                onclick="toggleStatusMenu(this)"
                                title="Status ändern"
                                class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                            >
                                <i class="fas fa-ellipsis-v text-xs"></i>
                            </button>
                            <div class="status-menu hidden absolute right-0 top-8 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-xl shadow-lg z-20 w-40 py-1">
                                <?php foreach ($statusConfig as $sKey => $sCfg): ?>
                                <button
                                    onclick="changeIdeaStatus(<?php echo $idea['id']; ?>, '<?php echo $sKey; ?>')"
                                    class="w-full text-left px-3 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-2 transition-colors"
                                >
                                    <span class="w-2 h-2 rounded-full <?php echo $sCfg['dot']; ?>"></span>
                                    <?php echo $sCfg['label']; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed mb-3 line-clamp-3"><?php echo nl2br(htmlspecialchars($idea['description'])); ?></p>

                <!-- Meta -->
                <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                    <div class="flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
                              style="background-color: <?php echo htmlspecialchars($avatarColor); ?>">
                            <?php echo htmlspecialchars($initials); ?>
                        </span>
                        <span><?php echo htmlspecialchars($submitterName); ?></span>
                    </div>
                    <span>·</span>
                    <span><?php echo date('d.m.Y', strtotime($idea['created_at'])); ?></span>
                    <span>·</span>
                    <span><i class="fas fa-arrow-up mr-0.5 text-green-500"></i><?php echo $upvotes; ?></span>
                    <span><i class="fas fa-arrow-down mr-0.5 text-red-400"></i><?php echo $downvotes; ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- New Idea Modal -->
<div id="ideaModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
        <!-- Modal Header -->
        <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-yellow-100 dark:bg-yellow-900/40 flex items-center justify-center">
                    <i class="fas fa-lightbulb text-yellow-500"></i>
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-50">Neue Idee einreichen</h2>
            </div>
            <button id="closeIdeaModal" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 space-y-4">
            <div id="ideaFormError" class="hidden p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-300"></div>

            <div>
                <label for="ideaTitle" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Titel <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    id="ideaTitle"
                    maxlength="200"
                    required
                    placeholder="Kurzer, aussagekräftiger Titel…"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-yellow-400/50 focus:border-yellow-400 dark:focus:border-yellow-500 transition-colors text-sm"
                >
            </div>

            <div>
                <label for="ideaDescription" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Beschreibung <span class="text-red-500">*</span>
                </label>
                <textarea
                    id="ideaDescription"
                    rows="5"
                    required
                    placeholder="Beschreibe Deine Idee so detailliert wie möglich…"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-yellow-400/50 focus:border-yellow-400 dark:focus:border-yellow-500 transition-colors text-sm resize-none"
                ></textarea>
            </div>

            <!-- Info note -->
            <div class="flex gap-2.5 p-3.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 rounded-xl">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 flex-shrink-0 text-sm"></i>
                <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed">
                    Deine Idee wird an den IBC-Vorstand und ERW weitergeleitet und sorgfältig geprüft.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 pb-6 flex gap-3">
            <button
                id="submitIdeaBtn"
                type="button"
                class="flex-1 flex items-center justify-center gap-2 px-5 py-2.5 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-semibold rounded-xl shadow-sm hover:shadow-md transition-all text-sm"
            >
                <i class="fas fa-paper-plane"></i>
                Einreichen
            </button>
            <button
                id="cancelIdeaBtn"
                type="button"
                class="px-5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-all text-sm"
            >
                Abbrechen
            </button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const CREATE_URL = <?php echo json_encode(asset('api/create_idea.php')); ?>;
const VOTE_URL   = <?php echo json_encode(asset('api/vote_idea.php')); ?>;
const STATUS_URL = <?php echo json_encode(asset('api/update_idea_status.php')); ?>;

// ── Modal ──────────────────────────────────────────────────────────────────
const ideaModal  = document.getElementById('ideaModal');
const openBtn    = document.getElementById('openIdeaModal');
const closeBtn   = document.getElementById('closeIdeaModal');
const cancelBtn  = document.getElementById('cancelIdeaBtn');
const submitBtn  = document.getElementById('submitIdeaBtn');
const titleInput = document.getElementById('ideaTitle');
const descInput  = document.getElementById('ideaDescription');
const formError  = document.getElementById('ideaFormError');

function openModal()  { ideaModal.classList.remove('hidden'); titleInput.focus(); }
function closeModal() {
    ideaModal.classList.add('hidden');
    titleInput.value = '';
    descInput.value  = '';
    formError.classList.add('hidden');
}

openBtn.addEventListener('click',   openModal);
closeBtn.addEventListener('click',  closeModal);
cancelBtn.addEventListener('click', closeModal);
ideaModal.addEventListener('click', e => { if (e.target === ideaModal) closeModal(); });

submitBtn.addEventListener('click', async () => {
    const title = titleInput.value.trim();
    const desc  = descInput.value.trim();

    formError.classList.add('hidden');

    if (!title || !desc) {
        formError.textContent = 'Bitte fülle alle Pflichtfelder aus.';
        formError.classList.remove('hidden');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Wird eingereicht…';

    try {
        const fd = new FormData();
        fd.append('csrf_token',  CSRF_TOKEN);
        fd.append('title',       title);
        fd.append('description', desc);

        const res  = await fetch(CREATE_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            closeModal();
            window.location.reload();
        } else {
            formError.textContent = data.error || 'Unbekannter Fehler.';
            formError.classList.remove('hidden');
        }
    } catch (err) {
        formError.textContent = 'Netzwerkfehler. Bitte versuche es erneut.';
        formError.classList.remove('hidden');
    } finally {
        submitBtn.disabled = false;
        submitBtn.setAttribute('aria-busy', 'false');
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>Einreichen';
    }
});

// ── Voting ─────────────────────────────────────────────────────────────────
async function castVote(ideaId, direction) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('idea_id',    ideaId);
    fd.append('vote',       direction);

    try {
        const res  = await fetch(VOTE_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { console.error(data.error); return; }

        const card    = document.querySelector(`[data-idea-id="${ideaId}"]`);
        if (!card) return;
        const upBtn   = card.querySelector('.upvote');
        const downBtn = card.querySelector('.downvote');
        const scoreEl = card.querySelector('.vote-score');
        const score   = data.upvotes - data.downvotes;

        const inactiveUp   = 'vote-btn upvote w-9 h-9 rounded-xl flex items-center justify-center transition-all bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-green-50 dark:hover:bg-green-900/30 hover:text-green-500 dark:hover:text-green-400 border border-gray-200 dark:border-gray-600';
        const activeUp     = 'vote-btn upvote w-9 h-9 rounded-xl flex items-center justify-center transition-all bg-green-500 text-white shadow-md';
        const inactiveDown = 'vote-btn downvote w-9 h-9 rounded-xl flex items-center justify-center transition-all bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-red-50 dark:hover:bg-red-900/30 hover:text-red-500 dark:hover:text-red-400 border border-gray-200 dark:border-gray-600';
        const activeDown   = 'vote-btn downvote w-9 h-9 rounded-xl flex items-center justify-center transition-all bg-red-500 text-white shadow-md';

        upBtn.className   = data.user_vote === 'up'   ? activeUp   : inactiveUp;
        downBtn.className = data.user_vote === 'down' ? activeDown : inactiveDown;

        scoreEl.textContent = score;
        scoreEl.className   = 'vote-score text-sm font-bold ' + (score > 0 ? 'text-green-600 dark:text-green-400' : score < 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-500 dark:text-gray-400');
    } catch (err) {
        console.error('Vote error:', err);
    }
}

// ── Status Menu (board only) ────────────────────────────────────────────────
function toggleStatusMenu(btn) {
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.status-menu').forEach(m => {
        if (m !== menu) m.classList.add('hidden');
    });
    menu.classList.toggle('hidden');
}

document.addEventListener('click', e => {
    if (!e.target.closest('[onclick^="toggleStatusMenu"]')) {
        document.querySelectorAll('.status-menu').forEach(m => m.classList.add('hidden'));
    }
});

async function changeIdeaStatus(ideaId, status) {
    document.querySelectorAll('.status-menu').forEach(m => m.classList.add('hidden'));
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('idea_id',    ideaId);
    fd.append('status',     status);
    try {
        const res  = await fetch(STATUS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) window.location.reload();
        else alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
    } catch (err) {
        alert('Netzwerkfehler.');
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
