import assert from 'node:assert/strict';
import test from 'node:test';
import TaskTracker from '../../resources/js/task-tracker.js';
import { layoutProcessGraph } from '../../resources/js/process-graph.js';

function createView() {
    const view = { ...TaskTracker.data(), token: 'test-token', groupId: '1', groupSequence: 1 };
    for (const [name, method] of Object.entries(TaskTracker.methods)) view[name] = method.bind(view);
    for (const [name, getter] of Object.entries(TaskTracker.computed)) {
        Object.defineProperty(view, name, { get: () => getter.call(view) });
    }
    view.closeModal = () => { view.modal = ''; };
    return view;
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((fulfill, fail) => { resolve = fulfill; reject = fail; });
    return { promise, resolve, reject };
}

const statuses = [{ id: 10 }, { id: 20 }];
const taskAt = (statusId) => [{ id: 1, status_id: statusId, type: 'feature' }];

function arrangeOverlappingReads(view, firstMembers) {
    let taskReads = 0, memberReads = 0;
    view.api = async (path) => {
        if (path.endsWith('/tasks')) return taskAt(++taskReads === 1 ? 10 : 20);
        if (path.endsWith('/statuses')) return statuses;
        if (path.endsWith('/members')) return ++memberReads === 1 ? firstMembers.promise : [];
        throw new Error(`Unexpected request: ${path}`);
    };
}

test('an older refresh cannot overwrite the latest task state', async () => {
    const view = createView(), firstMembers = deferred();
    arrangeOverlappingReads(view, firstMembers);
    const older = view.refresh();
    await view.refresh();
    assert.equal(view.tasks[0].status_id, 20);
    firstMembers.resolve([]);
    await older;
    assert.equal(view.tasks[0].status_id, 20);
    assert.equal(view.refreshing, false);
});

test('an older refresh failure cannot replace a successful refresh with an error', async () => {
    const view = createView(), firstMembers = deferred();
    arrangeOverlappingReads(view, firstMembers);
    const older = view.refresh();
    await view.refresh();
    firstMembers.reject(new Error('Old request failed'));
    await older;
    assert.equal(view.error, '');
    assert.equal(view.tasks[0].status_id, 20);
});

test('a current refresh failure remains visible and releases the loading indicator', async () => {
    const view = createView();
    view.api = async () => { throw new Error('Current request failed'); };
    await view.refresh();
    assert.equal(view.error, 'Current request failed');
    assert.equal(view.refreshing, false);
});

test('a pending read is invalidated when saving starts, before the write finishes', async () => {
    const view = createView(), firstMembers = deferred(), write = deferred();
    view.tasks = taskAt(20);
    view.statuses = statuses;
    view.editingTask = { id: 1, status_id: 20, manager_id: null };
    view.taskForm = { name: 'Task', description: 'Details', type: 'feature', status_id: 20, manager_id: '', deadline: '' };
    arrangeOverlappingReads(view, firstMembers);
    const read = view.api;
    view.api = (path, options) => options?.method === 'PUT' ? write.promise : read(path);
    const older = view.refresh();
    const saving = view.saveTask();
    firstMembers.resolve([]);
    await older;
    assert.equal(view.tasks[0].status_id, 20);
    assert.equal(view.saving, true);
    write.resolve({});
    await saving;
    assert.equal(view.tasks[0].status_id, 20);
    assert.equal(view.saving, false);
    assert.equal(view.formError, '');
});

test('an older group load cannot replace the selected group', async () => {
    const view = createView(), firstMembers = deferred();
    view.api = async (path) => {
        if (path.endsWith('/members')) return path.includes('/groups/1/') ? firstMembers.promise : [];
        if (path.endsWith('/statuses')) return statuses;
        if (path.endsWith('/tasks')) return taskAt(path.includes('/groups/1/') ? 10 : 20);
        throw new Error(`Unexpected request: ${path}`);
    };
    const older = view.selectGroup(1);
    await view.selectGroup(2);
    firstMembers.resolve([]);
    await older;
    assert.equal(view.groupId, '2');
    assert.equal(view.tasks[0].status_id, 20);
    assert.equal(view.loading, false);
});

test('a pending read cannot restore data after logout', async () => {
    const view = createView(), firstMembers = deferred();
    arrangeOverlappingReads(view, firstMembers);
    const pending = view.refresh();
    view.resetSession();
    firstMembers.resolve([]);
    await pending;
    assert.equal(view.token, '');
    assert.deepEqual(view.tasks, []);
    assert.equal(view.refreshing, false);
});

const graphNodes = ['A', 'B', 'C', 'D'].map((key) => ({ key, name: key }));
const transition = (from, to, count) => ({ from_key: from, to_key: to, count });

function labelsOverlap(first, second) {
    return Math.abs(first.labelX - second.labelX) < (first.labelWidth + second.labelWidth) / 2
        && Math.abs(first.labelY - second.labelY) < 24;
}

test('nested forward transitions keep both counts readable', () => {
    const graph = layoutProcessGraph(graphNodes, [transition('A', 'D', 7), transition('B', 'C', 11)]);
    assert.deepEqual(graph.edges.map((edge) => edge.count).sort((a, b) => a - b), [7, 11]);
    assert.equal(labelsOverlap(...graph.edges), false);
});

test('disjoint transitions share a level without increasing the graph height', () => {
    const single = layoutProcessGraph(graphNodes, [transition('A', 'B', 7)]);
    const disjoint = layoutProcessGraph(graphNodes, [transition('A', 'B', 7), transition('C', 'D', 11)]);
    assert.equal(disjoint.height, single.height);
    assert.equal(labelsOverlap(...disjoint.edges), false);
});

test('all labels and nodes fit inside the graph for dense forward and backward transitions', () => {
    const transitions = graphNodes.flatMap((from) => graphNodes.filter((to) => from !== to)
        .map((to) => transition(from.key, to.key, 12345)));
    const graph = layoutProcessGraph(graphNodes, transitions);
    assert.equal(graph.edges.length, transitions.length);
    for (const [index, edge] of graph.edges.entries()) {
        assert.ok(edge.labelX - edge.labelWidth / 2 >= 0);
        assert.ok(edge.labelX + edge.labelWidth / 2 <= graph.width);
        assert.ok(edge.labelY - 13 >= 0);
        assert.ok(edge.labelY + 11 <= graph.height);
        for (const other of graph.edges.slice(index + 1)) assert.equal(labelsOverlap(edge, other), false);
    }
    for (const node of graph.nodes) {
        assert.ok(node.y >= 0);
        assert.ok(node.y + 52 <= graph.height);
    }
});

test('graph layout is stable when transition order changes', () => {
    const transitions = [transition('A', 'D', 7), transition('B', 'C', 11), transition('D', 'A', 4)];
    const geometry = (graph) => new Map(graph.edges.map((edge) => [`${edge.from_key}->${edge.to_key}`, edge.path]));
    assert.deepEqual(geometry(layoutProcessGraph(graphNodes, transitions)), geometry(layoutProcessGraph(graphNodes, [...transitions].reverse())));
});
