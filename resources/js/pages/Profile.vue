<template>
    <div>
        <div class="jh-page-header">
            <div>
                <h1>Profile &amp; resumes</h1>
                <p>Your verified profile is the only source of truth for AI matching and application documents.</p>
            </div>
            <div class="d-flex gap-2">
                <button v-if="profile && !profile.is_verified" class="btn btn-success" @click="verify"><i class="bi bi-patch-check me-1"></i>Mark as verified</button>
                <span v-else-if="profile?.is_verified" class="jh-pill jh-pill-green align-self-center"><i class="bi bi-patch-check-fill"></i>Verified {{ date(profile.verified_at) }}</span>
                <button class="btn btn-primary" @click="save" :disabled="saving"><i class="bi bi-save me-1"></i>Save profile</button>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-4 d-flex flex-column gap-3">
                <div class="jh-card">
                    <div class="jh-card-header"><h2><i class="bi bi-file-earmark-person me-2 text-primary"></i>Resumes</h2></div>
                    <div class="p-3">
                        <label class="btn btn-ghost w-100 mb-2" :class="{ disabled: uploading }">
                            <span v-if="uploading" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-upload me-1"></i>Upload PDF or DOCX
                            <input type="file" class="d-none" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @change="upload" />
                        </label>
                        <div class="form-check small mb-3"><input class="form-check-input" type="checkbox" id="ow" v-model="overwrite" /><label class="form-check-label" for="ow">Overwrite existing profile fields with parsed data</label></div>
                        <div class="small text-muted mb-3"><i class="bi bi-shield-lock me-1"></i>Stored encrypted on this machine. Email, phone, address and profile URLs are redacted before resume text is sent to Claude for parsing.</div>
                        <div v-for="r in resumes" :key="r.id" class="jh-card jh-card-pad bg-transparent mb-2">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="min-w-0">
                                    <div class="fw-medium text-truncate">{{ r.label }} <span v-if="r.is_master" class="jh-pill jh-pill-blue ms-1">Master</span></div>
                                    <div class="small text-muted">{{ r.original_filename }} · {{ Math.round(r.size_bytes / 1024) }} KB</div>
                                    <div class="small mt-1">
                                        <span v-if="r.parse_status === 'pending'" class="text-primary"><span class="spinner-border spinner-border-sm me-1"></span>Parsing…</span>
                                        <span v-else-if="r.parse_status === 'parsed'" class="text-success"><i class="bi bi-check2 me-1"></i>Parsed {{ relative(r.parsed_at) }}</span>
                                        <span v-else class="text-danger"><i class="bi bi-x-circle me-1"></i>{{ r.parse_status }}</span>
                                    </div>
                                    <div v-if="r.parse_error" class="small text-warning mt-1">{{ r.parse_error }}</div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-ghost btn-icon btn-sm" data-bs-toggle="dropdown" aria-label="Resume actions"><i class="bi bi-three-dots"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" :href="`/api/resumes/${r.id}/download`">Download</a></li>
                                        <li><button class="dropdown-item" @click="makeMaster(r)">Set as master</button></li>
                                        <li><button class="dropdown-item" @click="reparse(r)">Re-parse into profile</button></li>
                                        <li><button class="dropdown-item text-danger" @click="remove(r)">Delete</button></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="jh-card" v-if="profile">
                    <div class="jh-card-header"><h2><i class="bi bi-person-lines-fill me-2 text-primary"></i>Contact (kept local)</h2></div>
                    <div class="p-3">
                        <label class="form-label">Full name</label><input class="form-control form-control-sm mb-2" v-model="profile.full_name" />
                        <label class="form-label">Email</label><input class="form-control form-control-sm mb-2" v-model="profile.email" />
                        <label class="form-label">Phone</label><input class="form-control form-control-sm mb-2" v-model="profile.phone" />
                        <label class="form-label">Location</label><input class="form-control form-control-sm mb-2" v-model="profile.location" />
                        <label class="form-label">LinkedIn URL</label><input class="form-control form-control-sm mb-2" v-model="profile.linkedin_url" />
                        <label class="form-label">Website</label><input class="form-control form-control-sm" v-model="profile.website_url" />
                    </div>
                </div>
            </div>

            <div class="col-xl-8 d-flex flex-column gap-3" v-if="profile">
                <div v-if="!profile.is_verified" class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Review everything below — especially AI-extracted skills — then click <strong>Mark as verified</strong>. Remove anything that is not accurate.</div>
                <div class="jh-card jh-card-pad">
                    <label class="form-label">Headline</label><input class="form-control mb-3" v-model="profile.headline" placeholder="e.g. Executive AI Consultant & Enterprise Transformation Advisor" />
                    <label class="form-label">Professional summary</label><textarea class="form-control" rows="5" v-model="profile.summary"></textarea>
                </div>

                <div class="jh-card">
                    <div class="jh-card-header"><h2>Skills inventory</h2><button class="btn btn-ghost btn-sm" @click="profile.skills.push({ name: '', category: 'other', evidence: '' })"><i class="bi bi-plus"></i> Add</button></div>
                    <div class="p-3">
                        <div v-for="(s, i) in profile.skills" :key="i" class="row g-2 mb-2">
                            <div class="col-md-4"><input class="form-control form-control-sm" v-model="s.name" placeholder="Skill" /></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" v-model="s.category"><option v-for="c in ['consulting', 'ai', 'technology', 'business', 'leadership', 'industry', 'tools', 'other']" :key="c">{{ c }}</option></select></div>
                            <div class="col-md-5"><input class="form-control form-control-sm" v-model="s.evidence" placeholder="Evidence from your experience" /></div>
                            <div class="col-md-1"><button class="btn btn-ghost btn-icon btn-sm" @click="profile.skills.splice(i, 1)" aria-label="Remove skill"><i class="bi bi-trash"></i></button></div>
                        </div>
                        <div v-if="!profile.skills.length" class="small text-muted">No skills yet. Upload a resume or add them manually.</div>
                    </div>
                </div>

                <div class="jh-card">
                    <div class="jh-card-header"><h2>Work history</h2><button class="btn btn-ghost btn-sm" @click="profile.work_history.push({ company: '', title: '', start: '', end: '', location: '', highlights: [] })"><i class="bi bi-plus"></i> Add</button></div>
                    <div class="p-3 d-flex flex-column gap-3">
                        <div v-for="(w, i) in profile.work_history" :key="i" class="jh-card jh-card-pad bg-transparent">
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><input class="form-control form-control-sm" v-model="w.title" placeholder="Title" /></div>
                                <div class="col-md-4"><input class="form-control form-control-sm" v-model="w.company" placeholder="Company" /></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" v-model="w.start" placeholder="Start" /></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" v-model="w.end" placeholder="End" /></div>
                            </div>
                            <textarea class="form-control form-control-sm" rows="4" :value="(w.highlights || []).join('\n')" @input="w.highlights = $event.target.value.split('\n').filter((l) => l.trim())" placeholder="One accomplishment per line"></textarea>
                            <div class="text-end mt-1"><button class="btn btn-link btn-sm text-danger p-0" @click="profile.work_history.splice(i, 1)">Remove</button></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="jh-card h-100">
                            <div class="jh-card-header"><h2>Certifications</h2><button class="btn btn-ghost btn-sm" @click="profile.certifications.push({ name: '', issuer: '', year: '' })"><i class="bi bi-plus"></i></button></div>
                            <div class="p-3">
                                <div v-for="(c, i) in profile.certifications" :key="i" class="d-flex gap-1 mb-2">
                                    <input class="form-control form-control-sm" v-model="c.name" placeholder="Name" />
                                    <input class="form-control form-control-sm" style="width: 120px" v-model="c.issuer" placeholder="Issuer" />
                                    <input class="form-control form-control-sm" style="width: 70px" v-model="c.year" placeholder="Year" />
                                    <button class="btn btn-ghost btn-icon btn-sm" @click="profile.certifications.splice(i, 1)"><i class="bi bi-trash"></i></button>
                                </div>
                                <div v-if="!profile.certifications.length" class="small text-muted">None listed.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="jh-card h-100">
                            <div class="jh-card-header"><h2>Education</h2><button class="btn btn-ghost btn-sm" @click="profile.education.push({ institution: '', degree: '', field: '', year: '' })"><i class="bi bi-plus"></i></button></div>
                            <div class="p-3">
                                <div v-for="(e, i) in profile.education" :key="i" class="d-flex gap-1 mb-2">
                                    <input class="form-control form-control-sm" v-model="e.institution" placeholder="Institution" />
                                    <input class="form-control form-control-sm" v-model="e.degree" placeholder="Degree" />
                                    <input class="form-control form-control-sm" style="width: 70px" v-model="e.year" placeholder="Year" />
                                    <button class="btn btn-ghost btn-icon btn-sm" @click="profile.education.splice(i, 1)"><i class="bi bi-trash"></i></button>
                                </div>
                                <div v-if="!profile.education.length" class="small text-muted">None listed.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="jh-card jh-card-pad">
                    <label class="form-label">Industries</label>
                    <TagInput v-model="profile.industries" />
                    <label class="form-label mt-3">Key achievements</label>
                    <TagInput v-model="profile.achievements" placeholder="Add an achievement and press Enter" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import api, { errorMessage } from '../api';
import { toast } from '../store';
import { date, relative } from '../format';
import TagInput from '../components/TagInput.vue';

const profile = ref(null);
const resumes = ref([]);
const uploading = ref(false);
const saving = ref(false);
const overwrite = ref(false);
let poll = null;

function normalize(p) {
    for (const k of ['skills', 'work_history', 'education', 'certifications', 'industries', 'achievements']) p[k] ||= [];
    return p;
}
async function load() {
    profile.value = normalize((await api.get('/profile')).data);
    await loadResumes();
}
async function loadResumes() {
    resumes.value = (await api.get('/resumes')).data;
    clearTimeout(poll);
    if (resumes.value.some((r) => r.parse_status === 'pending')) {
        poll = setTimeout(async () => {
            const wasPending = resumes.value.filter((r) => r.parse_status === 'pending').map((r) => r.id);
            await loadResumes();
            if (wasPending.some((id) => resumes.value.find((r) => r.id === id)?.parse_status !== 'pending')) {
                profile.value = normalize((await api.get('/profile')).data);
                toast('Resume parsed into your profile. Please review and verify.', 'success');
            }
        }, 3000);
    }
}
async function upload(e) {
    const file = e.target.files[0];
    if (!file) return;
    uploading.value = true;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('parse', '1');
    fd.append('overwrite', overwrite.value ? '1' : '0');
    try {
        await api.post('/resumes', fd);
        toast('Uploaded. Parsing in the background…', 'success');
        await loadResumes();
    } catch (err) {
        toast(errorMessage(err), 'error');
    } finally {
        uploading.value = false;
        e.target.value = '';
    }
}
async function save() {
    saving.value = true;
    try {
        const clean = { ...profile.value };
        clean.skills = clean.skills.filter((s) => s.name?.trim());
        clean.work_history = clean.work_history.filter((w) => w.company?.trim() && w.title?.trim());
        clean.certifications = clean.certifications.filter((c) => c.name?.trim());
        clean.education = clean.education.filter((e) => e.institution?.trim());
        for (const k of ['linkedin_url', 'website_url', 'email']) if (!clean[k]) clean[k] = null;
        const { data } = await api.put('/profile', clean);
        profile.value = normalize(data);
        toast(data.qualifications_changed ? 'Saved. Qualification changes will trigger re-analysis on the next run.' : 'Saved.', 'success');
    } catch (e) {
        toast(errorMessage(e), 'error');
    } finally {
        saving.value = false;
    }
}
async function verify() {
    await save();
    profile.value = normalize((await api.post('/profile/verify')).data);
    toast('Profile marked as verified.', 'success');
}
async function makeMaster(r) { await api.post(`/resumes/${r.id}/master`); loadResumes(); }
async function reparse(r) { await api.post(`/resumes/${r.id}/parse`, { overwrite: overwrite.value }); loadResumes(); }
async function remove(r) {
    if (!confirm(`Delete ${r.label}? The encrypted file is removed permanently.`)) return;
    await api.delete(`/resumes/${r.id}`);
    loadResumes();
}
onMounted(load);
onUnmounted(() => clearTimeout(poll));
</script>
