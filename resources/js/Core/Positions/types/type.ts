 export interface PositionProprities{
    id:number
    job_category_id:number
    name?:string
    description?:string
    status:number //0=>inactive 1=active
 }

 export interface JobCategoriesProprities{
    id:number
    name:string
    status:number
 }
