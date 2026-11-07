export function layoutProcessGraph(statuses, transitions) {
    const padding = 24, nodeHeight = 52, laneGap = 40;
    const nodes = statuses.map((status, index) => ({ ...status, x: 36 + index * 206 }));
    const byKey = new Map(nodes.map((node) => [node.key, node]));
    const spans = transitions.flatMap((edge) => {
        const from = byKey.get(edge.from_key || `status:${edge.from_status_id}`);
        const to = byKey.get(edge.to_key || `status:${edge.to_status_id}`);
        if (!from || !to) return [];
        return [{ edge, from, to, start: Math.min(from.x, to.x), end: Math.max(from.x, to.x), backward: to.x <= from.x }];
    }).sort((a, b) => a.start - b.start || a.end - b.end || a.from.key.localeCompare(b.from.key) || a.to.key.localeCompare(b.to.key));

    // Intersecting horizontal spans use separate lanes on their side of the nodes.
    const above = [], below = [];
    for (const span of spans) {
        const lanes = span.backward ? above : below;
        const available = lanes.findIndex((end) => end < span.start);
        span.lane = available === -1 ? lanes.length : available;
        lanes[span.lane] = span.end;
    }

    const nodeY = padding + above.length * laneGap;
    for (const node of nodes) node.y = nodeY;
    const edges = spans.map(({ edge, from, to, backward, lane }) => {
        const x1 = from.x + 82, x2 = to.x + 82;
        const y = backward ? nodeY : nodeY + nodeHeight;
        const labelY = y + (backward ? -1 : 1) * (lane + 1) * laneGap;
        // With equal endpoint heights, the cubic midpoint is y + 3/4 * (controlY - y).
        const curveY = y + (labelY - y) * 4 / 3;
        return {
            ...edge,
            path: `M ${x1} ${y} C ${x1} ${curveY}, ${x2} ${curveY}, ${x2} ${y}`,
            labelX: (x1 + x2) / 2,
            labelY,
            labelWidth: Math.max(30, String(edge.count).length * 8 + 12),
        };
    });
    return {
        nodes, edges,
        width: Math.max(680, nodes.length * 206 + 42),
        height: nodeY + nodeHeight + below.length * laneGap + (edges.length ? padding : 56),
    };
}
