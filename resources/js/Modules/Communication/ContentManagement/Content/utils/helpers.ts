import { Content, ContentType, HrPerson, CompanyUnit } from './type';

export const ContentHelpers = {
  isAnnouncement(content: Content): boolean {
    return content.type === ContentType.Announcement;
  },

  isNews(content: Content): boolean {
    return content.type === ContentType.News;
  },

  isEvent(content: Content): boolean {
    return content.type === ContentType.Event;
  },

  isInternal(content: Content): boolean {
    return content.type === ContentType.InternalNotice;
  },

  getTypeLabel(type: ContentType): string {
    const labels: Record<ContentType, string> = {
      [ContentType.Announcement]: 'Announcement',
      [ContentType.News]: 'News article',
      [ContentType.Event]: 'Event',
      [ContentType.InternalNotice]: 'Internal Notice'
    };
    return labels[type] || 'Content';
  },

  getFullName(person: HrPerson | null | undefined): string {
    if (!person) return 'Unknown';
    return [person.first_name, person.surname].filter(Boolean).join(' ') || 'Unknown';
  },

  getUnitPath(unit: CompanyUnit | null | undefined): string {
    if (!unit) return 'No unit';
    return unit.parent
      ? `${ContentHelpers.getUnitPath(unit.parent)} > ${unit.name}`
      : unit.name;
  },

  isUnitActive(unit: CompanyUnit | null | undefined): boolean {
    return unit?.active ?? false;
  }
};
