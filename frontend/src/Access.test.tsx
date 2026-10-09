import {afterEach,expect,it,vi} from 'vitest'
import {cleanup,render,screen} from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import '@testing-library/jest-dom/vitest'
import {Access} from './Access'
afterEach(()=>{cleanup();vi.unstubAllGlobals()})
it('preserves grants outside actor authority and requires a preview before saving',async()=>{
 const state={data:[{id:'member',name:'Colleague',email:'colleague@example.test',status:'active',requires_mfa:true,permissions:['company.read','audit.read']}],actor_membership_id:'admin',access_version:4,meta:{current_page:1,last_page:1,total:1},catalog:[{permission:'company.read',label:'View company',delegable:true},{permission:'workforce.read',label:'View workforce',delegable:true},{permission:'audit.read',label:'Read audit',delegable:false}]}
 const json=(v:unknown)=>Promise.resolve(new Response(JSON.stringify(v),{headers:{'Content-Type':'application/json'}}))
 let writes=0
 vi.stubGlobal('fetch',vi.fn((path:string,options:RequestInit)=>{
  if(path.includes('/access?page='))return json(state)
  if(path==='/sanctum/csrf-cookie')return Promise.resolve(new Response(null,{status:204}))
  if(options.method==='PUT'){writes++;expect(JSON.parse(options.body as string)).toEqual({version:4,reason:'Approved grant',permissions:['company.read','audit.read','workforce.read']});return json({data:{}})}
  throw new Error('Unexpected request '+path)
 }))
 render(<Access tenant="tenant" base="/api/v1/companies/company"/>)
 await userEvent.click(await screen.findByRole('button',{name:'Edit permissions for Colleague'}))
 expect(screen.getByRole('checkbox',{name:'Read audit (outside your authority)'})).toBeDisabled()
 await userEvent.click(screen.getByRole('checkbox',{name:'View workforce'}))
 await userEvent.type(screen.getByLabelText('Reason — avoid confidential details'),'Approved grant')
 await userEvent.click(screen.getByRole('button',{name:'Review changes'}))
 expect(writes).toBe(0)
 expect(screen.getByRole('region',{name:'Permission change preview'})).toBeInTheDocument()
 await userEvent.click(screen.getByRole('button',{name:'Apply permissions'}))
 await screen.findByText('Permissions saved.')
 expect(writes).toBe(1)
})
