import { nextTick } from 'vue';
import { layoutProcessGraph } from './process-graph.js';

const storageKey = 'tasktracker-token';
const readToken = () => {
    try { return sessionStorage.getItem(storageKey) || ''; } catch { return ''; }
};
const persistToken = (token) => {
    try {
        if (token) sessionStorage.setItem(storageKey, token);
        else sessionStorage.removeItem(storageKey);
    } catch { /* Keep the current session usable when browser storage is blocked. */ }
};
const emptyTask = () => ({ name: '', description: '', status_id: '', type: 'general', manager_id: '', deadline: '' });

export default {
    data() {
        return {
            token: readToken(), user: null, booting: true, loading: false, refreshing: false,
            authMode: 'login', auth: { name: '', email: '', password: '' }, authBusy: false,
            error: '', notice: '', groups: [], groupId: '', statuses: [], tasks: [], members: [],
            tab: 'board', search: '', expiredOnly: false, typeFilter: '', managerFilter: '',
            analytics: null, analyticsBusy: false, analyticsError: '', knownTaskTypes: [], knownManagers: [], modal: '', saving: false,
            formError: '', groupForm: { name: '', description: '' },
            statusForm: {}, editingStatus: null, taskForm: emptyTask(), editingTask: null,
            history: [], historyBusy: false, historyError: '', mobileMenu: false, now: Date.now(),
            refreshTimer: null, clockTimer: null, analyticsSequence: 0, groupSequence: 0,
            sessionSequence: 0, historySequence: 0, authSequence: 0, mutationSequence: 0,
            refreshSequence: 0, groupRequestSequence: 0, sessionError: '', returnFocus: null, clockOffset: 0,
        };
    },
    computed: {
        currentGroup() { return this.groups.find((group) => String(group.id) === this.groupId); },
        orderedStatuses() { return [...this.statuses].sort((a, b) => a.position - b.position || a.id - b.id); },
        filteredTasks() {
            const query = this.search.trim().toLocaleLowerCase('ru');
            return this.tasks.filter((task) => {
                const text = [task.name, task.description, task.type, task.manager?.name].join(' ').toLocaleLowerCase('ru');
                return (!query || text.includes(query)) && (!this.expiredOnly || this.isExpired(task))
                    && (!this.typeFilter || task.type === this.typeFilter)
                    && (!this.managerFilter || String(task.manager_id) === this.managerFilter);
            });
        },
        taskTypes() { return [...new Set([this.typeFilter, ...this.tasks.map((task) => task.type), ...this.knownTaskTypes])].filter(Boolean).sort(); },
        filterManagers() {
            const managers = [...this.knownManagers, ...this.tasks.map((task) => task.manager), ...this.members].filter((manager) => manager?.id != null);
            return [...new Map(managers.map((manager) => [Number(manager.id), manager])).values()];
        },
        taskManagers() {
            const manager = this.editingTask?.manager;
            return manager && !this.members.some((member) => Number(member.id) === Number(manager.id)) ? [...this.members, manager] : this.members;
        },
        unplacedTasks() {
            const statusIds = new Set(this.statuses.map((status) => Number(status.id)));
            return this.filteredTasks.filter((task) => !statusIds.has(Number(task.status_id)));
        },
        openCount() { return this.tasks.filter((task) => !task.completed).length; },
        expiredCount() { return this.tasks.filter((task) => this.isExpired(task)).length; },
        doneCount() { return this.tasks.filter((task) => task.completed).length; },
        statusChanged() { return !this.editingTask || Number(this.taskForm.status_id) !== Number(this.editingTask.status_id); },
        selectedStatus() { return this.statuses.find((status) => status.id === Number(this.taskForm.status_id)); },
        graphLayout() {
            const nodes = new Map();
            for (const status of this.analytics?.statuses || []) nodes.set(status.key || `status:${status.status_id}`, { ...status, key: status.key || `status:${status.status_id}` });
            for (const edge of this.analytics?.transitions || []) {
                for (const side of ['from', 'to']) {
                    const key = edge[`${side}_key`] || `status:${edge[`${side}_status_id`]}`;
                    if (!nodes.has(key)) nodes.set(key, { key, status_id: edge[`${side}_status_id`], name: edge[`${side}_name`], color: '#9ba8be', visits: 0, time_percent: 0 });
                }
            }
            const ordered = [...nodes.values()].sort((a, b) => {
                const left = this.statuses.find((status) => status.id === a.status_id)?.position || 0;
                const right = this.statuses.find((status) => status.id === b.status_id)?.position || 0;
                return left - right;
            });
            return layoutProcessGraph(ordered, this.analytics?.transitions || []);
        },
        graphNodes() { return this.graphLayout.nodes; },
        graphWidth() { return this.graphLayout.width; },
        graphHeight() { return this.graphLayout.height; },
        graphEdges() { return this.graphLayout.edges; },
    },
    async mounted() {
        if (this.token) {
            try { await this.startSession(); } catch (error) { this.error = error.message; }
        }
        this.booting = false;
        this.clockTimer = setInterval(() => { this.now = Date.now() + this.clockOffset; }, 30000);
        this.refreshTimer = setInterval(() => {
            if (this.token && this.groupId && !this.modal && !document.hidden && !this.loading && !this.refreshing) this.refresh();
        }, 60000);
        document.addEventListener('keydown', this.handleDialogKeys);
    },
    beforeUnmount() {
        clearInterval(this.clockTimer); clearInterval(this.refreshTimer);
        document.removeEventListener('keydown', this.handleDialogKeys);
        document.body.style.overflow = '';
    },
    methods: {
        async api(path, options = {}) {
            const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 20000);
            const token = this.token, session = this.sessionSequence;
            try {
                const response = await fetch(`/api${path}`, {
                    ...options, signal: controller.signal,
                    headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
                    ...(options.body ? { body: JSON.stringify(options.body) } : {}),
                });
                const body = await response.json().catch(() => ({}));
                const serverDate = Date.parse(response.headers.get('Date') || '');
                if (Number.isFinite(serverDate) && token === this.token && session === this.sessionSequence) {
                    this.clockOffset = serverDate - Date.now();
                    this.now = serverDate;
                }
                if (!response.ok) {
                    if (response.status === 401 && token && this.token === token && session === this.sessionSequence) {
                        this.resetSession();
                        this.error = 'Сессия истекла. Войдите в аккаунт ещё раз.';
                    }
                    const details = Object.values(body.errors || {}).flat().join(' ');
                    throw new Error(details || body.message || `Ошибка запроса (${response.status}).`);
                }
                return body;
            } catch (error) {
                if (error.name === 'AbortError') throw new Error('Сервер не ответил вовремя. Попробуйте ещё раз.');
                if (error instanceof TypeError) throw new Error('Не удалось связаться с сервером. Проверьте подключение.');
                throw error;
            } finally { clearTimeout(timeout); }
        },
        async authenticate() {
            if (this.authBusy) return;
            const sequence = ++this.authSequence;
            const session = this.sessionSequence;
            this.authBusy = true; this.error = '';
            try {
                const body = this.authMode === 'register' ? { ...this.auth } : { email: this.auth.email, password: this.auth.password };
                const result = await this.api(`/${this.authMode}`, { method: 'POST', body });
                if (sequence !== this.authSequence || session !== this.sessionSequence) return;
                this.token = result.token; persistToken(this.token); this.auth.password = '';
                await this.startSession();
            } catch (error) { if (sequence === this.authSequence) this.error = error.message; }
            finally { if (sequence === this.authSequence) this.authBusy = false; }
        },
        async startSession() {
            this.loading = true; this.sessionError = ''; this.error = '';
            const sequence = ++this.sessionSequence;
            const token = this.token;
            try {
                const [user, groups] = await Promise.all([this.api('/profile'), this.api('/groups')]);
                if (sequence !== this.sessionSequence || token !== this.token || !token) return;
                this.user = user; this.groups = groups;
                if (groups.length) await this.selectGroup(groups[0].id);
            } catch (error) {
                if (sequence === this.sessionSequence && token === this.token) this.sessionError = error.message;
            } finally { if (sequence === this.sessionSequence) this.loading = false; }
        },
        resetSession() {
            this.token = ''; persistToken(''); this.user = null; this.groups = []; this.groupId = '';
            this.tasks = []; this.statuses = []; this.members = []; this.analytics = null; this.knownTaskTypes = []; this.knownManagers = [];
            this.groupSequence++; this.analyticsSequence++; this.sessionSequence++; this.historySequence++;
            this.authSequence++; this.mutationSequence++; this.refreshSequence++; this.groupRequestSequence++;
            this.loading = false; this.analyticsBusy = false; this.saving = false; this.refreshing = false;
            this.authBusy = false; this.sessionError = ''; this.notice = ''; this.clockOffset = 0; this.mobileMenu = false;
            this.now = Date.now(); this.closeModal();
        },
        async logout() {
            const context = this.captureContext(false);
            try { await this.api('/logout', { method: 'POST' }); }
            catch (error) { if (this.isCurrentContext(context)) this.error = error.message; }
            finally { if (this.isCurrentContext(context)) this.resetSession(); }
        },
        captureContext(withGroup = true) {
            return { token: this.token, session: this.sessionSequence, ...(withGroup ? { groupId: this.groupId, group: this.groupSequence } : {}) };
        },
        isCurrentContext(context) {
            return context.token === this.token && context.session === this.sessionSequence
                && (context.group === undefined || (context.group === this.groupSequence && context.groupId === this.groupId));
        },
        beginMutation() {
            if (this.saving) return null;
            this.groupRequestSequence++;
            this.saving = true; this.formError = '';
            return { ...this.captureContext(), mutation: ++this.mutationSequence };
        },
        isCurrentMutation(context) { return this.isCurrentContext(context) && context.mutation === this.mutationSequence; },
        async selectGroup(id) {
            if (this.saving) return;
            this.groupId = String(id); this.typeFilter = ''; this.managerFilter = ''; this.search = '';
            this.expiredOnly = false; this.mobileMenu = false; this.tasks = []; this.statuses = [];
            this.members = []; this.analytics = null; this.knownTaskTypes = []; this.knownManagers = []; this.analyticsSequence++; this.error = ''; this.loading = true;
            const sequence = ++this.groupSequence;
            try { await this.loadGroup(sequence); }
            catch (error) { if (sequence === this.groupSequence) this.error = error.message; }
            finally { if (sequence === this.groupSequence) this.loading = false; }
        },
        async loadGroup(sequence = this.groupSequence) {
            if (sequence !== this.groupSequence) return;
            const context = this.captureContext(), id = context.groupId;
            const request = ++this.groupRequestSequence;
            const isCurrent = () => request === this.groupRequestSequence && this.isCurrentContext(context);
            try {
                const [tasks, statuses, members] = await Promise.all([this.api(`/groups/${id}/tasks`), this.api(`/groups/${id}/statuses`), this.api(`/groups/${id}/members`)]);
                if (!isCurrent()) return;
                this.tasks = tasks; this.statuses = statuses; this.members = members; this.now = Date.now() + this.clockOffset;
                this.knownTaskTypes = [...new Set([...this.knownTaskTypes, ...tasks.map((task) => task.type)])];
                if (this.tab === 'analytics') await this.loadAnalytics();
            } catch (error) {
                if (isCurrent()) throw error;
            }
        },
        async refresh() {
            if (!this.groupId || !this.token) return;
            const refresh = ++this.refreshSequence;
            this.refreshing = true; this.error = '';
            const sequence = this.groupSequence;
            try { await this.loadGroup(sequence); }
            catch (error) { if (refresh === this.refreshSequence && sequence === this.groupSequence) this.error = error.message; }
            finally { if (refresh === this.refreshSequence) this.refreshing = false; }
        },
        async setTab(tab) { this.tab = tab; if (tab === 'analytics' && this.groupId) await this.loadAnalytics(); },
        async loadAnalytics() {
            if (!this.groupId) return;
            const sequence = ++this.analyticsSequence;
            this.analyticsBusy = true; this.analyticsError = ''; this.analytics = null;
            const query = new URLSearchParams();
            if (this.typeFilter) query.set('type', this.typeFilter);
            if (this.managerFilter) query.set('manager_id', this.managerFilter);
            try {
                const data = await this.api(`/groups/${this.groupId}/analytics/process-debt?${query}`);
                if (sequence === this.analyticsSequence) {
                    this.analytics = data;
                    this.knownTaskTypes = [...new Set([...this.knownTaskTypes, ...data.types.map((row) => row.type)])];
                    const managers = data.managers.filter((row) => row.manager_id != null).map((row) => ({ id: row.manager_id, name: row.name }));
                    this.knownManagers = [...new Map([...this.knownManagers, ...managers].map((manager) => [Number(manager.id), manager])).values()];
                }
            } catch (error) { if (sequence === this.analyticsSequence) this.analyticsError = error.message; }
            finally { if (sequence === this.analyticsSequence) this.analyticsBusy = false; }
        },
        async filterChanged() { if (this.tab === 'analytics') await this.loadAnalytics(); },
        tasksIn(status) { return this.filteredTasks.filter((task) => Number(task.status_id) === status.id); },
        isExpired(task) { return !task.completed && (Boolean(task.expired) || (Boolean(task.deadline) && new Date(task.deadline).getTime() <= this.now)); },
        percent(value) { return `${Number(value || 0).toLocaleString('ru-RU', { maximumFractionDigits: 1 })}%`; },
        duration(seconds) {
            const amount = Math.max(0, Number(seconds) || 0);
            if (amount === 0) return '0 мин';
            if (amount < 60) return '< 1 мин';
            if (amount < 3600) return `${Math.floor(amount / 60)} мин`;
            if (amount < 86400) return `${(amount / 3600).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} ч`;
            return `${(amount / 86400).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} дн`;
        },
        date(value) { return value ? new Date(value).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Не задан'; },
        deadlineLabel(task) {
            if (task.completed) return 'Завершена';
            if (!task.deadline) return 'Срок не задан';
            const seconds = (new Date(task.deadline).getTime() - this.now) / 1000;
            if (task.expired && seconds > 0) return 'Просрочена';
            return seconds <= 0 ? `Просрочена на ${this.duration(-seconds)}` : `Осталось ${this.duration(seconds)}`;
        },
        categoryLabel(value) { return ({ work: 'Работа', waiting: 'Ожидание', done: 'Завершение' })[value] || value; },
        typeLabel(value) { return value === 'general' ? 'Общая' : value; },
        initial(name) { return String(name || '?').trim().slice(0, 1).toLocaleUpperCase('ru'); },
        openModal(name) {
            if (this.saving) return;
            this.returnFocus = document.activeElement; this.formError = ''; this.notice = ''; this.modal = name;
            document.body.style.overflow = 'hidden';
            nextTick(() => (document.querySelector('.dialog input, .dialog select, .dialog textarea') || document.querySelector('.dialog button'))?.focus());
        },
        closeModal() {
            if (this.saving) return;
            this.modal = ''; this.history = []; this.historyError = ''; this.historySequence++;
            document.body.style.overflow = ''; this.returnFocus?.focus?.(); this.returnFocus = null;
        },
        handleDialogKeys(event) {
            if (!this.modal) return;
            if (event.key === 'Escape') { this.closeModal(); return; }
            if (event.key !== 'Tab') return;
            const elements = [...document.querySelectorAll('.dialog button, .dialog input, .dialog select, .dialog textarea, .dialog a')].filter((el) => !el.disabled && el.offsetParent !== null);
            const first = elements[0], last = elements[elements.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        },
        newGroup() { this.groupForm = { name: '', description: '' }; this.openModal('group'); },
        newStatus() {
            this.editingStatus = null;
            this.statusForm = { name: '', description: '', color: '#5b69d4', category: 'work', sla_minutes: 1440, position: this.statuses.length ? Math.max(...this.statuses.map((status) => status.position)) + 1 : 0 };
            this.openModal('status');
        },
        editStatus(status) {
            this.editingStatus = status;
            this.statusForm = { name: status.name, description: status.description || '', color: status.color, category: status.category, sla_minutes: status.sla_minutes, position: status.position };
            this.openModal('status');
        },
        newTask(status = null) {
            this.editingTask = null;
            this.taskForm = { ...emptyTask(), status_id: status?.id || this.orderedStatuses[0]?.id || '', manager_id: this.user?.id || '' };
            this.openModal('task');
        },
        async editTask(task) {
            this.editingTask = task;
            const hasLocalStatus = this.statuses.some((status) => Number(status.id) === Number(task.status_id));
            this.taskForm = { name: task.name, description: task.description || '', status_id: hasLocalStatus ? task.status_id : '', type: task.type || 'general', manager_id: task.manager_id || '', deadline: '' };
            this.openModal('task'); this.history = []; this.historyError = ''; this.historyBusy = true;
            const sequence = ++this.historySequence;
            try {
                const history = await this.api(`/groups/${this.groupId}/tasks/${task.id}/history`);
                if (sequence === this.historySequence) this.history = history;
            } catch (error) { if (sequence === this.historySequence) this.historyError = error.message; }
            finally { if (sequence === this.historySequence) this.historyBusy = false; }
        },
        taskStatusChanged() { this.taskForm.deadline = ''; },
        async saveGroup() {
            const context = this.beginMutation();
            if (!context) return;
            try {
                const group = await this.api('/group', { method: 'POST', body: this.groupForm });
                if (!this.isCurrentMutation(context)) return;
                this.groups.push(group); this.saving = false; this.closeModal(); await this.selectGroup(group.id);
                if (context.token === this.token && context.session === this.sessionSequence && this.groupId === String(group.id)) this.notice = 'Группа создана. Добавьте статусы и задачи.';
            } catch (error) { if (this.isCurrentMutation(context)) this.formError = error.message; }
            finally { if (this.isCurrentMutation(context)) this.saving = false; }
        },
        async saveStatus() {
            const context = this.beginMutation();
            if (!context) return;
            try {
                const path = this.editingStatus ? `/groups/${context.groupId}/statuses/${this.editingStatus.id}` : `/groups/${context.groupId}/status`;
                await this.api(path, { method: this.editingStatus ? 'PUT' : 'POST', body: { ...this.statusForm, group_id: Number(context.groupId) } });
                if (!this.isCurrentMutation(context)) return;
                this.saving = false; this.closeModal(); await this.refresh();
                if (this.isCurrentMutation(context)) this.notice = 'Статус сохранён.';
            } catch (error) { if (this.isCurrentMutation(context)) this.formError = error.message; }
            finally { if (this.isCurrentMutation(context)) this.saving = false; }
        },
        async saveTask() {
            if (!this.selectedStatus) { this.formError = 'Выберите статус этой рабочей группы.'; return; }
            const context = this.beginMutation();
            if (!context) return;
            try {
                const body = { name: this.taskForm.name.trim(), description: this.taskForm.description, group_id: Number(context.groupId), status_id: Number(this.taskForm.status_id), type: this.taskForm.type.trim() || 'general' };
                const managerId = this.taskForm.manager_id ? Number(this.taskForm.manager_id) : null;
                const previousManagerId = this.editingTask?.manager_id == null ? null : Number(this.editingTask.manager_id);
                if (!this.editingTask || managerId !== previousManagerId) body.manager_id = managerId;
                if (!this.editingTask) body.completed = false;
                if (this.statusChanged && this.taskForm.deadline) body.deadline = new Date(this.taskForm.deadline).toISOString();
                const path = this.editingTask ? `/groups/${context.groupId}/tasks/${this.editingTask.id}` : `/groups/${context.groupId}/task`;
                await this.api(path, { method: this.editingTask ? 'PUT' : 'POST', body });
                if (!this.isCurrentMutation(context)) return;
                this.saving = false; this.closeModal(); await this.refresh();
                if (this.isCurrentMutation(context)) this.notice = 'Задача сохранена.';
            } catch (error) { if (this.isCurrentMutation(context)) this.formError = error.message; }
            finally { if (this.isCurrentMutation(context)) this.saving = false; }
        },
    },
    template: `
    <div v-if="booting" class="boot-screen"><span class="spinner"></span><p>Открываем рабочее пространство…</p></div>
    <main v-else-if="!token" class="auth-layout">
        <section class="auth-story">
            <a class="brand" href="/" aria-label="TaskTracker, главная"><span class="brand-mark">↗</span><span>TaskTracker<small>PROCESS INTELLIGENCE</small></span></a>
            <div class="auth-pitch"><span class="eyebrow">Видеть процесс целиком</span><h1>Работа движется.<br><em>А где она ждёт?</em></h1><p>Сроки каждого этапа, история переходов и узкие места процесса — в одном рабочем пространстве.</p><div class="story-flow" aria-hidden="true"><span>Анализ</span><b>→</b><span class="flow-wait">Согласование</span><b>→</b><span>Готово</span></div><div class="story-caption"><span class="pulse-dot"></span>Меньше догадок. Больше прозрачности.</div></div>
            <p class="auth-footnote">Process Debt · осознанное управление потоком</p>
        </section>
        <section class="auth-form-wrap"><form class="auth-form" @submit.prevent="authenticate">
            <span class="eyebrow">Рабочее пространство</span><h2>{{ authMode === 'login' ? 'С возвращением' : 'Начните с команды' }}</h2><p class="muted">{{ authMode === 'login' ? 'Войдите, чтобы продолжить работу с задачами.' : 'Создайте аккаунт и настройте свой процесс.' }}</p>
            <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
            <label v-if="authMode === 'register'">Имя<input v-model="auth.name" autocomplete="name" required maxlength="255" placeholder="Как к вам обращаться"></label>
            <label>Электронная почта<input v-model="auth.email" type="email" autocomplete="email" required maxlength="255" placeholder="name@company.ru"></label>
            <label>Пароль<input v-model="auth.password" type="password" :autocomplete="authMode === 'register' ? 'new-password' : 'current-password'" required placeholder="Ваш пароль"></label>
            <button class="button primary full" :disabled="authBusy">{{ authBusy ? 'Подождите…' : authMode === 'login' ? 'Войти в пространство →' : 'Создать аккаунт →' }}</button>
            <p class="auth-switch">{{ authMode === 'login' ? 'Ещё нет аккаунта?' : 'Уже зарегистрированы?' }} <button type="button" class="text-button" @click="authMode = authMode === 'login' ? 'register' : 'login'; error = ''">{{ authMode === 'login' ? 'Зарегистрироваться' : 'Войти' }}</button></p>
        </form></section>
    </main>
    <div v-else class="workspace">
        <button v-if="mobileMenu" class="sidebar-backdrop" aria-label="Закрыть меню" @click="mobileMenu = false"></button>
        <aside class="sidebar" :class="{ visible: mobileMenu }" :inert="Boolean(modal)">
            <a class="brand" href="/" aria-label="TaskTracker, главная"><span class="brand-mark">↗</span><span>TaskTracker<small>PROCESS INTELLIGENCE</small></span></a>
            <div class="sidebar-section"><span class="sidebar-label">ПРОСТРАНСТВА</span><button class="icon-button sidebar-plus" aria-label="Создать рабочую группу" @click="newGroup">+</button></div>
            <nav class="group-navigation" aria-label="Рабочие группы"><button v-for="group in groups" :key="group.id" :class="['group-link', { active: String(group.id) === groupId }]" @click="selectGroup(group.id)"><span class="group-icon">{{ initial(group.name) }}</span><span>{{ group.name }}</span><span v-if="String(group.id) === groupId" class="active-indicator"></span></button><p v-if="!groups.length" class="sidebar-empty">Создайте первую группу для задач вашей команды.</p></nav>
            <button class="sidebar-create" @click="newGroup">＋ Новая группа</button>
            <div class="sidebar-note"><span>◷</span><h3>У каждого этапа свой срок</h3><p>Настройте SLA статусов, чтобы видеть, где накапливается долг процесса.</p></div>
            <div class="user-profile"><span class="avatar">{{ initial(user?.name) }}</span><div><strong>{{ user?.name || 'Пользователь' }}</strong><span>{{ user?.email }}</span></div><button class="icon-button" title="Выйти" aria-label="Выйти из аккаунта" @click="logout">↪</button></div>
        </aside>
        <div class="workspace-main" :inert="Boolean(modal) || mobileMenu">
            <header class="topbar"><button class="icon-button mobile-toggle" aria-label="Открыть меню групп" @click="mobileMenu = !mobileMenu">☰</button><div class="breadcrumb">Рабочее пространство <span>/</span><strong>{{ currentGroup?.name || 'Обзор' }}</strong></div><div class="topbar-actions"><span class="live-label"><span class="pulse-dot"></span>Обновление раз в минуту</span><button class="icon-button refresh-button" :class="{ spinning: refreshing }" :disabled="!groupId || refreshing || loading" aria-label="Обновить данные" @click="refresh">↻</button></div></header>
            <div class="page-content">
                <div v-if="error" class="alert alert-error" role="alert"><span>{{ error }}</span><button aria-label="Скрыть сообщение" @click="error = ''">×</button></div>
                <div v-if="notice" class="alert alert-success" role="status"><span>{{ notice }}</span><button aria-label="Скрыть сообщение" @click="notice = ''">×</button></div>
                <div v-if="sessionError" class="welcome-state"><span class="welcome-symbol">↻</span><h1>Не удалось загрузить пространство</h1><p role="alert">{{ sessionError }}</p><button class="button primary" :disabled="loading" @click="startSession">{{ loading ? 'Загружаем…' : 'Повторить загрузку' }}</button></div><div v-else-if="!groups.length && !loading" class="welcome-state"><span class="welcome-symbol">↗</span><span class="eyebrow">Всё начинается с процесса</span><h1>Создайте рабочую группу</h1><p>Добавьте этапы, задайте их сроки и заведите задачи.<br>Аналитика появится по мере движения работы.</p><button class="button primary" @click="newGroup">＋ Создать первую группу</button></div>
                <template v-else>
                    <div class="page-heading"><div><span class="eyebrow">Поток команды</span><h1>{{ currentGroup?.name || 'Загрузка…' }}</h1><p class="muted">{{ currentGroup?.description || 'Задачи, сроки и прозрачность каждого этапа.' }}</p></div><div class="heading-actions"><button class="button secondary" :disabled="!groupId || loading" @click="newStatus">＋ Статус</button><button class="button primary" :disabled="!statuses.length || loading" @click="newTask()">＋ Задача</button></div></div>
                    <div class="tabs" role="tablist" aria-label="Представление рабочего пространства"><button id="tab-board" role="tab" :aria-selected="tab === 'board'" aria-controls="panel-board" :class="{ active: tab === 'board' }" @click="setTab('board')">Доска задач <span>{{ tasks.length }}</span></button><button id="tab-analytics" role="tab" :aria-selected="tab === 'analytics'" aria-controls="panel-analytics" :class="{ active: tab === 'analytics' }" @click="setTab('analytics')">Process Debt <span class="tab-icon">↗</span></button></div>
                    <div class="filter-bar"><div v-if="tab === 'board'" class="search-field"><span aria-hidden="true">⌕</span><input v-model="search" aria-label="Поиск задач" placeholder="Найти задачу…"></div><label class="filter-select"><span class="sr-only">Тип задачи</span><select v-model="typeFilter" @change="filterChanged"><option value="">Все типы задач</option><option v-for="type in taskTypes" :key="type" :value="type">{{ typeLabel(type) }}</option></select></label><label class="filter-select"><span class="sr-only">Ответственный менеджер</span><select v-model="managerFilter" @change="filterChanged"><option value="">Все менеджеры</option><option v-for="member in filterManagers" :key="member.id" :value="String(member.id)">{{ member.name }}</option></select></label><label v-if="tab === 'board'" class="check-filter"><input v-model="expiredOnly" type="checkbox">Только просроченные</label><span v-if="tab === 'analytics'" class="filter-caption">За всё время наблюдений</span></div>
                    <div v-if="loading" class="content-loading" role="status"><span class="spinner"></span>Загружаем процесс…</div>
                    <section v-else-if="tab === 'board'" id="panel-board" role="tabpanel" aria-labelledby="tab-board">
                        <div class="board-overview"><div><span class="metric-dot blue"></span><strong>{{ openCount }}</strong><span>в работе</span></div><div><span class="metric-dot red"></span><strong>{{ expiredCount }}</strong><span>просрочено</span></div><div><span class="metric-dot green"></span><strong>{{ doneCount }}</strong><span>завершено</span></div><span class="board-hint">Нажмите на задачу для перехода и истории</span></div>
                        <div v-if="!statuses.length" class="empty-state"><span class="empty-symbol">▥</span><h2>Опишите этапы процесса</h2><p>Например: Анализ → Разработка → Ревью → Готово.<br>Для каждого этапа можно задать SLA и категорию.</p><button class="button primary" @click="newStatus">Добавить первый статус</button></div>
                        <div v-else class="kanban-board"><section v-for="status in orderedStatuses" :key="status.id" class="kanban-column"><div class="column-heading"><span class="status-dot" :style="{ backgroundColor: status.color }"></span><h2>{{ status.name }}</h2><span class="column-count">{{ tasksIn(status).length }}</span><button class="icon-button" :aria-label="'Настройки статуса ' + status.name" @click="editStatus(status)">⋯</button></div><div class="column-meta">{{ categoryLabel(status.category) }} <span>· SLA {{ duration(status.sla_minutes * 60) }}</span></div><div class="column-tasks"><button v-for="task in tasksIn(status)" :key="task.id" class="task-card" :class="{ overdue: isExpired(task), completed: task.completed }" @click="editTask(task)"><div class="task-card-top"><span class="task-type">{{ typeLabel(task.type) }}</span><span class="task-number">#{{ task.id }}</span></div><h3>{{ task.name }}</h3><p v-if="task.description" class="task-description">{{ task.description }}</p><div class="task-deadline" :class="{ danger: isExpired(task), success: task.completed }"><span aria-hidden="true">{{ task.completed ? '✓' : '◷' }}</span>{{ deadlineLabel(task) }}</div><div class="task-card-footer"><span>{{ date(task.deadline) }}</span><span v-if="task.manager" class="mini-avatar" :title="task.manager.name">{{ initial(task.manager.name) }}</span><span v-else class="unassigned">Без менеджера</span></div></button><p v-if="!tasksIn(status).length" class="column-empty">Задач пока нет</p></div><button class="column-add" @click="newTask(status)">＋ Добавить задачу</button></section></div>
                        <article v-if="unplacedTasks.length" class="panel unplaced-panel"><div class="panel-heading"><div><h3>Задачи вне этапов · {{ unplacedTasks.length }}</h3><p>Текущий статус этих задач не входит в группу. Откройте карточку и выберите подходящий этап, чтобы вернуть задачу на доску.</p></div><button v-if="!statuses.length" class="button secondary" @click="newStatus">＋ Создать статус</button></div><div class="unplaced-tasks-grid"><button v-for="task in unplacedTasks" :key="task.id" class="task-card" :class="{ overdue: isExpired(task) }" @click="editTask(task)"><div class="task-card-top"><span class="task-type">{{ typeLabel(task.type) }}</span><span class="task-number">#{{ task.id }}</span></div><h3>{{ task.name }}</h3><p v-if="task.description" class="task-description">{{ task.description }}</p><div class="task-deadline" :class="{ danger: isExpired(task) }">{{ deadlineLabel(task) }}</div><div class="task-card-footer"><span>{{ date(task.deadline) }}</span><span class="repair-action">Выбрать этап →</span></div></button></div></article><p v-if="tasks.length && !filteredTasks.length" class="filter-empty">По выбранным фильтрам задач не найдено.</p>
                    </section>
                    <section v-else id="panel-analytics" role="tabpanel" aria-labelledby="tab-analytics" :aria-busy="analyticsBusy">
                        <div v-if="analyticsError" class="alert alert-error" role="alert">{{ analyticsError }}<button class="text-button" @click="loadAnalytics">Повторить</button></div>
                        <div v-if="analyticsBusy" class="analytics-loading" role="status"><span class="spinner small"></span>Пересчитываем аналитику…</div>
                        <template v-if="analytics">
                            <div class="analytics-heading"><div><span class="eyebrow">Bottleneck Analytics</span><h2>Где процесс теряет время</h2></div><span class="updated-label">Расчёт: {{ date(analytics.generated_at) }}</span></div>
                            <div class="metrics-grid"><article class="metric-card primary-metric"><span>Долг процесса</span><strong>{{ percent(analytics.summary.debt_percent) }}</strong><p>{{ duration(analytics.summary.debt_seconds) }} сверх SLA этапов</p><div class="mini-meter"><i :style="{ width: Math.min(100, analytics.summary.debt_percent) + '%' }"></i></div></article><article class="metric-card"><span>Время ожидания</span><strong>{{ percent(analytics.summary.waiting_percent) }}</strong><p>{{ duration(analytics.summary.waiting_seconds) }} в статусах ожидания</p></article><article class="metric-card"><span>Возвраты назад</span><strong>{{ analytics.summary.rework_count }}</strong><p>переходов на более ранний этап</p></article><article class="metric-card"><span>Просрочено сейчас</span><strong>{{ analytics.summary.expired_tasks }}</strong><p>по текущим назначениям и фильтрам</p></article></div>
                            <div v-if="analytics.insights.length" class="insights"><span class="insight-symbol">✦</span><div><span class="eyebrow">Наблюдения по процессу</span><p v-for="(insight, index) in analytics.insights" :key="index">{{ insight }}</p></div></div>
                            <div v-if="!analytics.statuses.length" class="empty-state"><span class="empty-symbol">↗</span><h2>Здесь появится ваш процесс</h2><p>Создайте задачи и переводите их между статусами.<br>Отчёт строится по реальной истории этапов.</p></div>
                            <template v-else>
                                <div class="analytics-two-columns"><article class="panel"><div class="panel-heading"><div><h3>Время по этапам</h3><p>Доля от всего наблюдаемого времени</p></div><span class="panel-kicker">{{ duration(analytics.summary.total_seconds) }}</span></div><div class="status-bars"><div v-for="status in analytics.statuses" :key="status.key || status.status_id" class="status-bar-row"><div class="bar-label"><span><i class="status-dot" :style="{ backgroundColor: status.color }"></i>{{ status.name }}</span><strong>{{ percent(status.time_percent) }}</strong></div><div class="bar-track"><span :style="{ width: Math.min(100, status.time_percent) + '%', backgroundColor: status.color }"></span></div><div class="bar-caption"><span>{{ duration(status.total_seconds) }} · {{ status.visits }} посещений</span><span :class="{ danger: status.debt_seconds > 0 }">Долг {{ duration(status.debt_seconds) }}</span></div></div></div></article><article class="panel"><div class="panel-heading"><div><h3>Типы задач</h3><p>Какая работа чаще застревает</p></div></div><div class="type-list"><div v-for="row in analytics.types" :key="row.type" class="type-row"><div><strong>{{ typeLabel(row.type) }}</strong><span>{{ row.task_count }} задач · ожидание {{ percent(row.waiting_percent) }}</span></div><div><b :class="{ danger: row.debt_percent > 0 }">{{ percent(row.debt_percent) }}</b><span>доля долга</span></div></div></div></article></div>
                                <article class="panel process-graph-panel"><div class="panel-heading"><div><h3>Граф движения задач</h3><p>Фактические переходы между этапами. Числа на линиях — количество переходов.</p></div><div class="graph-legend"><span><i class="legend-line"></i>Переход</span><span><i class="legend-line rework"></i>Возврат</span><span><i class="legend-debt"></i>Превышение SLA</span></div></div><div class="graph-scroll"><svg :viewBox="'0 0 ' + graphWidth + ' ' + graphHeight" :height="graphHeight" :style="{ minWidth: graphWidth + 'px' }" class="process-graph" role="img" aria-label="Граф переходов задач между статусами"><defs><marker id="arrow-forward" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#a1aec5"/></marker><marker id="arrow-rework" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#d87065"/></marker></defs><g v-for="(edge, index) in graphEdges" :key="index"><path :d="edge.path" fill="none" :stroke="edge.rework_count > 0 ? '#d87065' : '#a1aec5'" :stroke-dasharray="edge.rework_count > 0 ? '5 4' : undefined" stroke-width="2" :marker-end="edge.rework_count > 0 ? 'url(#arrow-rework)' : 'url(#arrow-forward)'"/></g><g v-for="(edge, index) in graphEdges" :key="index"><rect :x="edge.labelX - edge.labelWidth / 2" :y="edge.labelY - 13" :width="edge.labelWidth" height="24" rx="12" fill="#fff"/><text :x="edge.labelX" :y="edge.labelY + 3" text-anchor="middle" :fill="edge.rework_count > 0 ? '#ad4b43' : '#68758a'" font-size="12">{{ edge.count }}</text></g><g v-for="node in graphNodes" :key="node.key"><title>{{ node.name }}: {{ percent(node.time_percent) }} времени; долг {{ percent(node.debt_percent) }} ({{ duration(node.debt_seconds) }})</title><rect :x="node.x" :y="node.y" width="164" height="52" rx="10" :fill="node.debt_seconds > 0 ? '#fff2ed' : '#fff'" :stroke="node.debt_seconds > 0 ? '#d99a83' : node.color" stroke-width="1.5"/><circle :cx="node.x + 15" :cy="node.y + 18" r="4" :fill="node.color"/><text :x="node.x + 26" :y="node.y + 22" fill="#23314b" font-size="12">{{ node.name.length > 18 ? node.name.slice(0, 17) + '…' : node.name }}</text><text :x="node.x + 14" :y="node.y + 40" fill="#7b879a" font-size="11">{{ percent(node.time_percent) }} времени · {{ node.visits }} входов</text></g><text v-if="!graphEdges.length" x="36" :y="graphHeight - 18" fill="#7b879a" font-size="12">Переходы появятся после первой смены статуса.</text></svg></div></article>
                                <article class="panel"><div class="panel-heading"><div><h3>Ответственные менеджеры</h3><p>Менеджер и тип фиксируются при входе в этап; изменения применяются со следующего этапа.</p></div></div><div class="table-scroll"><table class="analytics-table"><thead><tr><th scope="col">Менеджер</th><th scope="col">Задачи</th><th scope="col">Время в этапах</th><th scope="col">Ожидание</th><th scope="col">Сверх SLA</th><th scope="col">Доля долга</th></tr></thead><tbody><tr v-for="manager in analytics.managers" :key="manager.manager_id ?? ('unassigned:' + manager.name)"><th scope="row"><span class="table-person"><span class="mini-avatar">{{ initial(manager.name) }}</span>{{ manager.name || 'Без менеджера' }}</span></th><td>{{ manager.task_count }}</td><td>{{ duration(manager.total_seconds) }}</td><td>{{ duration(manager.waiting_seconds) }}</td><td>{{ duration(manager.debt_seconds) }}</td><td><span class="debt-badge" :class="{ high: manager.debt_percent >= 30 }">{{ percent(manager.debt_percent) }}</span></td></tr></tbody></table></div></article>
                            </template>
                            <div class="methodology"><span>ⓘ</span><p><strong>Как читать отчёт.</strong> Долг процесса = время сверх дедлайнов этапов / всё наблюдаемое время. Это показатель нарушения SLA, а не доказанная потеря производительности. Ожидание считается по категории статуса; возврат — переход на более раннюю позицию. У старых задач учитывается только история после включения наблюдения. В выбранной истории {{ analytics.summary.task_count }} уникальных задач; завершённые этапы не накапливают время.</p></div>
                        </template>
                    </section>
                </template>
            </div>
        </div>
        <div v-if="modal" class="modal-backdrop" @click.self="closeModal"><section class="dialog" :class="{ 'task-dialog': modal === 'task' }" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
            <header class="dialog-header"><div><span class="eyebrow">{{ modal === 'task' ? 'Задача и её путь' : 'Настройка пространства' }}</span><h2 id="dialog-title">{{ modal === 'group' ? 'Новая рабочая группа' : modal === 'status' ? editingStatus ? 'Настройка статуса' : 'Новый статус' : editingTask ? 'Задача #' + editingTask.id : 'Новая задача' }}</h2></div><button class="icon-button" :disabled="saving" aria-label="Закрыть окно" @click="closeModal">×</button></header>
            <div v-if="formError" class="alert alert-error dialog-error" role="alert">{{ formError }}</div>
            <form v-if="modal === 'group'" class="dialog-form" @submit.prevent="saveGroup"><label>Название группы<input v-model="groupForm.name" maxlength="100" required placeholder="Например, продуктовая команда"></label><label>Описание<textarea v-model="groupForm.description" required maxlength="255" rows="3" placeholder="Над чем работает команда"></textarea></label><footer class="dialog-footer"><button type="button" class="button secondary" :disabled="saving" @click="closeModal">Отмена</button><button class="button primary" :disabled="saving">{{ saving ? 'Создаём…' : 'Создать группу' }}</button></footer></form>
            <form v-else-if="modal === 'status'" class="dialog-form" @submit.prevent="saveStatus"><label>Название статуса<input v-model="statusForm.name" required maxlength="100" placeholder="Например, Ready for Approval"></label><label>Описание<textarea v-model="statusForm.description" required maxlength="255" rows="2"></textarea></label><div class="form-row"><label>Категория<select v-model="statusForm.category"><option value="work">Работа</option><option value="waiting">Ожидание</option><option value="done">Завершение</option></select></label><label>Цвет<input v-model="statusForm.color" type="text" required maxlength="255" placeholder="#5b69d4 или blue"></label></div><div class="form-row"><label>SLA, минут<input v-model.number="statusForm.sla_minutes" type="number" min="1" max="525600" step="1" required></label><label>Позиция этапа<input v-model.number="statusForm.position" type="number" min="0" max="10000" step="1" required></label></div><p class="form-help">SLA задаёт срок нового этапа. Меньшая позиция — более ранний этап; переход назад считается возвратом. Изменения настроек не переписывают историю и сроки текущих задач. Для смены категории сначала переместите все задачи из статуса.</p><footer class="dialog-footer"><button type="button" class="button secondary" :disabled="saving" @click="closeModal">Отмена</button><button class="button primary" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Сохранить статус' }}</button></footer></form>
            <div v-else class="task-dialog-body"><form class="dialog-form" @submit.prevent="saveTask"><label>Название задачи<input v-model="taskForm.name" required maxlength="100" placeholder="Что нужно сделать?"></label><label>Описание<textarea v-model="taskForm.description" required maxlength="255" rows="3" placeholder="Контекст, результат и важные детали"></textarea></label><div class="form-row"><label>Статус<select v-model="taskForm.status_id" required @change="taskStatusChanged"><option disabled value="">Выберите статус этой группы</option><option v-for="status in orderedStatuses" :key="status.id" :value="status.id">{{ status.name }}</option></select></label><label>Тип задачи<input v-model="taskForm.type" maxlength="100" required list="task-types" placeholder="general"><datalist id="task-types"><option v-for="type in taskTypes" :key="type" :value="type"></option></datalist></label></div><div v-if="!statuses.length" class="task-status-warning"><p>Сначала создайте статус в этой группе, затем откройте задачу снова и выберите её этап.</p><button type="button" class="text-button" :disabled="saving" @click="closeModal(); newStatus()">Создать статус →</button></div><p v-else-if="editingTask && !taskForm.status_id" class="form-help">Текущий статус не входит в эту группу. Выберите этап для задачи; переход запишется в историю с новым дедлайном.</p><label>Ответственный менеджер<select v-model="taskForm.manager_id"><option value="">Без менеджера</option><option v-for="member in taskManagers" :key="member.id" :value="member.id">{{ member.name }}</option></select></label><p v-if="editingTask" class="form-help">Ответственность и тип в аналитике изменятся со следующего этапа.</p><template v-if="statusChanged"><label>Дедлайн нового этапа <span class="optional-label">необязательно</span><input v-model="taskForm.deadline" type="datetime-local"></label><p class="form-help">Без ручного срока сервер применит SLA: {{ duration((selectedStatus?.sla_minutes || 1440) * 60) }}. Время вводится в вашем часовом поясе. {{ selectedStatus?.category === 'done' ? 'Задача будет завершена и не будет считаться просроченной.' : '' }}</p></template><div v-else class="current-deadline"><span>Дедлайн текущего этапа</span><strong :class="{ danger: editingTask && isExpired(editingTask) }">{{ date(editingTask?.deadline) }}</strong><p>Новый срок задаётся при смене статуса. Редактирование текста не обнуляет просрочку.</p></div><footer class="dialog-footer"><button type="button" class="button secondary" :disabled="saving" @click="closeModal">Отмена</button><button class="button primary" :disabled="saving">{{ saving ? 'Сохраняем…' : 'Сохранить' }}</button></footer></form><aside v-if="editingTask" class="history-panel"><h3>История движения</h3><p class="muted">Время и ответственность на каждом этапе.</p><p v-if="historyBusy" class="muted" role="status">Загрузка истории…</p><div v-else-if="historyError" class="alert alert-error">{{ historyError }}</div><p v-else-if="!history.length" class="muted">История пока не записана.</p><ol v-else class="history-list"><li v-for="entry in history" :key="entry.id"><i class="history-dot" :style="{ backgroundColor: entry.status_color }"></i><div class="history-entry-top"><strong>{{ entry.status_name }}</strong><span v-if="entry.is_rework" class="rework-tag">Возврат</span></div><p>{{ date(entry.entered_at) }} → {{ entry.left_at ? date(entry.left_at) : 'сейчас' }}</p><p>{{ duration((new Date(entry.left_at || now).getTime() - new Date(entry.entered_at).getTime()) / 1000) }} в этапе</p><p class="history-owner">{{ entry.manager_name || 'Без менеджера' }} · {{ typeLabel(entry.task_type) }}</p><p v-if="entry.deadline" class="history-sla">Дедлайн: {{ date(entry.deadline) }}</p></li></ol></aside></div>
        </section></div>
    </div>`,
};
