<?php

namespace App\Form;

use App\Entity\Doc;
use App\Entity\Session;
use App\Entity\Target;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

class DocType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'form.doc.title.label',
                'attr' => [
                    'class' => "form-control form-control-lg",
                    'placeholder' => $this->translator->trans('form.doc.title.placeholder'),
                ]
            ])
            ->add('doc', TextareaType::class, [
                'label' => 'form.doc.doc.label',
                'required' => false,
                'attr' => [
                    'class' => "form-control",
                    'placeholder' => '',
                    'style' => "display:none"
                ]
            ])
            ->add('target', EntityType::class, [
                'attr' => [
                    'class' => "form-control form-control-sm",
                    'placeholder' => 'Target'
                ],
                'class' => Target::class,
                'required' => false,
                'choice_label' => 'name',
            ])
            ->add('session', EntityType::class, [
                'attr' => [
                    'class' => "form-control form-control-sm",
                    'placeholder' => 'Session'
                ],
                'class' => Session::class,
                'required' => false,
                'choice_label' => fn(Session $s) => ($s->getTarget() ? $s->getTarget()->getName() . ' — ' : '') . ($s->getStartedAt()?->format('Y-m-d') ?? '#' . $s->getId()),
                'query_builder' => fn(EntityRepository $r): QueryBuilder => $r->createQueryBuilder('s')->leftJoin('s.target', 't')->orderBy('s.startedAt', 'DESC'),
            ])
            ->add('icon', TextType::class, [
                'attr' => [
                    "data-placement"=>"bottomRight",
                    "class" => "form-control icp icp-auto form-control-sm"
                ] ])
            ->add('tags', HiddenType::class)
        ;

        $builder->get('tags')->addModelTransformer(new CallbackTransformer(
            fn(?array $tagsAsArray) => $tagsAsArray ? json_encode(array_values($tagsAsArray)) : '[]',
            fn(?string $tagsAsString) => $tagsAsString ? json_decode($tagsAsString, true) ?? [] : [],
        ));

        $builder->add('uploadedFile', FileType::class, [
                'label' => 'form.doc.upload.label',
                'required' => false,
                'mapped' => false, // Important: mapped manually,
                'attr' => [
                    'class' => "form-control",
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Doc::class,
        ]);
    }
}
