import { describe, it, expect } from 'vitest';
import { mapTaskToFullCalendar, type ApiTask } from '../taskMapper';

describe('Tasks on Calendar', () => {

  it('maps a task to a FullCalendar event with task type', () => {
    const task: ApiTask = {
      id: 42,
      title: 'Review PR',
      due_date: '20260320',
      due_time: null,
      percent_complete: 0,
      status: 'pending',
      type: 'T',
      created_by: 'admin',
      description: '',
      access: 'P',
    };

    const event = mapTaskToFullCalendar(task);
    expect(event).not.toBeNull();

    expect(event!.id).toBe('task-42');
    expect(event!.title).toContain('Review PR');
    expect(event!.start).toBe('2026-03-20');
    expect(event!.allDay).toBe(true);
    expect(event!.extendedProps?.isTask).toBe(true);
  });

  it('maps a task with due time to a timed event', () => {
    const task: ApiTask = {
      id: 43,
      title: 'Deploy',
      due_date: '20260320',
      due_time: '140000',
      percent_complete: 0,
      status: 'pending',
      type: 'T',
      created_by: 'admin',
      description: '',
      access: 'P',
    };

    const event = mapTaskToFullCalendar(task);
    expect(event).not.toBeNull();
    expect(event!.start).toBe('2026-03-20T14:00:00');
    expect(event!.allDay).toBe(false);
  });

  it('completed task has distinct classNames', () => {
    const task: ApiTask = {
      id: 44,
      title: 'Done Task',
      due_date: '20260320',
      due_time: null,
      percent_complete: 100,
      status: 'completed',
      type: 'T',
      created_by: 'admin',
      description: '',
      access: 'P',
    };

    const event = mapTaskToFullCalendar(task);
    expect(event).not.toBeNull();
    expect(event!.classNames).toContain('task-completed');
  });

  it('pending task has task-pending className', () => {
    const task: ApiTask = {
      id: 45,
      title: 'Pending Task',
      due_date: '20260320',
      due_time: null,
      percent_complete: 0,
      status: 'pending',
      type: 'T',
      created_by: 'admin',
      description: '',
      access: 'P',
    };

    const event = mapTaskToFullCalendar(task);
    expect(event).not.toBeNull();
    expect(event!.classNames).toContain('task-pending');
  });

  it('task without due date is not mapped', () => {
    const task: ApiTask = {
      id: 46,
      title: 'No Date',
      due_date: null,
      due_time: null,
      percent_complete: 0,
      status: 'pending',
      type: 'T',
      created_by: 'admin',
      description: '',
      access: 'P',
    };

    const event = mapTaskToFullCalendar(task);
    expect(event).toBeNull();
  });
});
