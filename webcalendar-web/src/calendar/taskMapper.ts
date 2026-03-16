import type { EventInput } from '@fullcalendar/core';

export interface ApiTask {
  id: number;
  title: string;
  due_date: string | null;
  due_time: string | null;
  percent_complete: number;
  status: string;
  type: string;
  created_by: string;
  description: string;
  access: string;
}

function formatDate(dateStr: string): string {
  return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
}

function formatTime(timeStr: string): string {
  return `${timeStr.slice(0, 2)}:${timeStr.slice(2, 4)}:${timeStr.slice(4, 6)}`;
}

/**
 * Maps an API task to a FullCalendar EventInput, or null if the task has no due date.
 */
export function mapTaskToFullCalendar(task: ApiTask): EventInput | null {
  if (!task.due_date) return null;

  const isCompleted = task.percent_complete >= 100;
  const hasDueTime = task.due_time && task.due_time !== '000000';

  let start: string;
  if (hasDueTime) {
    start = `${formatDate(task.due_date)}T${formatTime(task.due_time!)}`;
  } else {
    start = formatDate(task.due_date);
  }

  return {
    id: `task-${task.id}`,
    title: `${isCompleted ? '✅' : '☐'} ${task.title}`,
    start,
    allDay: !hasDueTime,
    classNames: [isCompleted ? 'task-completed' : 'task-pending'],
    extendedProps: {
      isTask: true,
      taskId: task.id,
      percent_complete: task.percent_complete,
      status: task.status,
      created_by: task.created_by,
    },
  };
}

export function mapTasksToFullCalendar(tasks: ApiTask[]): EventInput[] {
  return tasks.map(mapTaskToFullCalendar).filter((e): e is EventInput => e !== null);
}
